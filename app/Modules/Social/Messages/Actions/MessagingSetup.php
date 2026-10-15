<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\FacebookMessaging;
use App\Modules\Social\Platforms\Facebook\FacebookMessagingException;
use App\Modules\Social\Platforms\Facebook\FacebookPageSubscriptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns direct-message capture on or off for one connected Facebook Page (ADR 0031).
 *
 * On: the operator side must be ready; the Page token must hold the messaging permissions (checked with
 * `debug_token`, the missing ones are named); the Page is claimed platform-wide (the same claim the audience comments
 * use, so one Page feeds one workspace); the app's Page webhook fields are extended with `messages` and
 * `message_echoes`, MERGED with whatever is subscribed already (comments' `feed`, lead ads' `leadgen`); and, when
 * the operator switched Instagram on, the Instagram professional account linked to the Page is resolved and written to
 * the claim so the central webhook can route its events.
 *
 * Off: only the messaging fields are removed from the subscription; the claim stays while comments still use it;
 * every stored conversation and message is kept. The Page token is read only from the encrypted account attribute and
 * is never put in a message, an audit entry or a log line.
 */
final class MessagingSetup
{
    public function __construct(
        private readonly FacebookMessaging $graph,
        private readonly MessagingAvailability $availability,
        private readonly CommentSources $claims,
        private readonly AccountManager $accounts,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The account's setting row, created switched off when absent. */
    public function settingFor(SocialAccount $account): MessagingSetting
    {
        return MessagingSetting::query()->firstOrCreate(['social_account_id' => $account->id], ['state' => MessagingSetting::OFF, 'instagram_state' => MessagingSetting::OFF]);
    }

    /** @throws ValidationException */
    public function enable(SocialAccount $account): MessagingSetting
    {
        if ($account->platform !== 'facebook') {
            throw ValidationException::withMessages(['account' => __('Direct messages are available for Facebook Pages only.')]);
        }

        if ($account->status !== AccountStatus::Connected || $account->access_token === null) {
            throw ValidationException::withMessages(['account' => __('Connect the Page again before switching on messages.')]);
        }

        $problem = $this->availability->operatorProblem();

        if ($problem !== null) {
            throw ValidationException::withMessages(['account' => $this->availability->operatorMessage($problem)]);
        }

        $setting = $this->settingFor($account);
        $token = (string) $account->access_token;
        $granted = $this->checked($account, $setting, fn (): ?array => $this->graph->grantedScopes($token));
        $missing = $granted === null ? [] : array_values(array_diff(MessagingAvailability::messengerScopes(), $granted));
        $setting->forceFill(['granted_scopes' => $granted, 'missing_scopes' => $missing])->save();

        if ($missing !== []) {
            $this->failed($setting, 'permission_missing', __('The Page connection is missing permissions needed for messages.'));

            throw ValidationException::withMessages(['account' => __('This Page connection is missing permissions for messages: :scopes. Connect the Page again and approve all of them.', ['scopes' => implode(', ', $missing)])]);
        }

        $this->claims->claim($account, __('This Page already sends its comments or messages to another workspace. A Page can feed only one workspace.'));

        $this->checked($account, $setting, function () use ($account, $token): void {
            $this->subscribe($account, $token, MessagingAvailability::webhookFields(), []);
        }, releaseClaim: true);

        [$instagramState, $instagramId] = MessagingAvailability::instagramEnabled()
            ? $this->instagram($account, $token, $granted)
            : [MessagingSetting::OFF, null];

        return DB::transaction(function () use ($account, $setting, $instagramState, $instagramId): MessagingSetting {
            $setting->forceFill([
                'is_enabled' => true,
                'state' => MessagingSetting::ON,
                'instagram_state' => $instagramState,
                'instagram_account_id' => $instagramState === MessagingSetting::ON ? $instagramId : null,
                'last_error_code' => null,
                'last_error' => null,
                'enabled_at' => now(),
            ])->save();
            $this->audit->record('social.messages.capture_enabled', $account, ['platform' => $account->platform, 'instagram' => $instagramState]);

            return $setting;
        });
    }

    /** Stop capturing a Page's direct messages. Conversations and messages already stored are kept. */
    public function disable(SocialAccount $account): MessagingSetting
    {
        $setting = $this->settingFor($account);

        if ($account->status === AccountStatus::Connected && $account->access_token !== null) {
            try {
                $this->subscribe($account, (string) $account->access_token, [], MessagingAvailability::webhookFields());
            } catch (FacebookMessagingException $exception) {
                // Best effort: without the setting switched on the receiver drops this Page's messages anyway.
                if ($exception->kind === FacebookMessagingException::TOKEN) {
                    $this->accounts->markNeedsReauthorization($account, $exception->getMessage());
                }
            }
        }

        return DB::transaction(function () use ($account, $setting): MessagingSetting {
            $setting->forceFill([
                'is_enabled' => false,
                'state' => MessagingSetting::OFF,
                'instagram_state' => MessagingSetting::OFF,
                'instagram_account_id' => null,
                'enabled_at' => null,
            ])->save();
            $this->releaseClaim($account);
            $this->audit->record('social.messages.capture_disabled', $account, ['platform' => $account->platform]);

            return $setting;
        });
    }

    /**
     * The webhook fields the Page's other features need now: comments' `feed`, lead ads' `leadgen`. Used when Meta's
     * own list cannot be read, so switching messages never drops them.
     *
     * @return list<string>
     */
    public function otherFeatureFields(SocialAccount $account): array
    {
        $fields = [];

        if (CommentSource::query()->where('account_id', $account->id)->where('is_enabled', true)->exists()) {
            $fields[] = 'feed';
        }

        if (LeadAdsPage::query()->where('account_id', $account->id)->first()?->isSubscribed() === true) {
            $fields[] = 'leadgen';
        }

        return $fields;
    }

    /**
     * Add and remove Page webhook fields, keeping every other field the app receives.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     *
     * @throws FacebookMessagingException
     */
    private function subscribe(SocialAccount $account, string $token, array $add, array $remove): void
    {
        try {
            $current = $this->graph->subscribedFields($account->external_id, $token);
        } catch (FacebookMessagingException $exception) {
            if (in_array($exception->kind, [FacebookMessagingException::TOKEN, FacebookMessagingException::PERMISSION], true)) {
                throw $exception;
            }

            $current = [];
        }

        $fields = FacebookPageSubscriptions::merge([...$current, ...$this->otherFeatureFields($account)], $add, $remove);
        $this->graph->setSubscribedFields($account->external_id, $token, $fields);
    }

    /**
     * Resolve the Instagram account linked to the Page and write it to the Page's claim.
     *
     * @param  list<string>|null  $granted
     * @return array{0: string, 1: string|null}
     */
    private function instagram(SocialAccount $account, string $token, ?array $granted): array
    {
        if ($granted !== null && array_diff(MessagingAvailability::instagramScopes(), $granted) !== []) {
            return [MessagingSetting::MISSING_PERMISSIONS, null];
        }

        try {
            $instagramId = $this->graph->linkedInstagramAccount($account->external_id, $token);
        } catch (FacebookMessagingException) {
            return [MessagingSetting::FAILED, null];
        }

        if ($instagramId === null) {
            return [MessagingSetting::NOT_LINKED, null];
        }

        try {
            $updated = PageClaim::query()
                ->where('tenant_id', $this->context->requireId())->where('account_id', $account->id)
                ->update(['instagram_id' => $instagramId]);
        } catch (UniqueConstraintViolationException) {
            return [MessagingSetting::TAKEN, null];
        }

        return $updated === 1 ? [MessagingSetting::ON, $instagramId] : [MessagingSetting::FAILED, null];
    }

    /** Drop the Instagram routing, and the claim itself unless the Page's comments still use it. */
    private function releaseClaim(SocialAccount $account): void
    {
        $claim = PageClaim::query()->where('tenant_id', $this->context->requireId())->where('account_id', $account->id);

        if (CommentSource::query()->where('account_id', $account->id)->where('is_enabled', true)->exists()) {
            $claim->update(['instagram_id' => null]);

            return;
        }

        $claim->delete();
    }

    /**
     * Run a Graph call; a refusal is recorded on the setting (a rejected token also on the account) and becomes a
     * validation message.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws ValidationException
     */
    private function checked(SocialAccount $account, MessagingSetting $setting, callable $call, bool $releaseClaim = false): mixed
    {
        try {
            return $call();
        } catch (FacebookMessagingException $exception) {
            if ($exception->kind === FacebookMessagingException::TOKEN) {
                $this->accounts->markNeedsReauthorization($account, $exception->getMessage());
            }

            if ($releaseClaim && ! $setting->is_enabled) {
                $this->releaseClaim($account);
            }

            $this->failed($setting, $exception->errorCode, $exception->getMessage());

            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
        }
    }

    private function failed(MessagingSetting $setting, string $code, string $message): void
    {
        $setting->forceFill([
            'state' => $setting->is_enabled ? MessagingSetting::ON : MessagingSetting::FAILED,
            'last_error_code' => Str::limit($code, 60, ''),
            'last_error' => Str::limit($message, 480, ''),
        ])->save();
    }
}

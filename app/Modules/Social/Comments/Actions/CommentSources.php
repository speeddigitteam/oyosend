<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\CommentProviders;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns comment ingestion on or off for one connected Page.
 *
 * Turning it on claims the Page platform-wide (one workspace per Page, enforced by a unique index) and asks the
 * platform to send this application the Page's `feed` events. A Page that cannot be subscribed still works through
 * the polling fallback; the state says so honestly.
 */
final class CommentSources
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommentGateway $gateway,
        private readonly CommentProviders $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function enable(SocialAccount $account): CommentSource
    {
        if ($account->status !== AccountStatus::Connected) {
            throw ValidationException::withMessages(['account' => __('Connect this account again before turning comments on.')]);
        }

        if (! $this->gateway->available($account)) {
            throw ValidationException::withMessages(['account' => __('Comments are not available for this platform on this installation.')]);
        }

        $this->claim($account);

        $source = DB::transaction(function () use ($account): CommentSource {
            $source = CommentSource::query()->firstOrNew(['account_id' => $account->id]);
            $source->fill(['is_enabled' => true, 'consecutive_failures' => 0, 'last_error_code' => null])->save();
            $this->audit->record('social.comments.source_enabled', $account, ['platform' => $account->platform]);

            return $source;
        });

        return $this->subscribe($account, $source);
    }

    public function disable(SocialAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            CommentSource::query()->where('account_id', $account->id)->update(['is_enabled' => false, 'webhook_state' => CommentSource::NONE]);

            // Direct-message capture routes through the same claim (ADR 0031): it stays while messages still use it.
            if (! MessagingSetting::query()->where('social_account_id', $account->id)->where('is_enabled', true)->exists()) {
                PageClaim::query()->where('account_id', $account->id)->where('tenant_id', $this->context->requireId())->delete();
            }

            $this->audit->record('social.comments.source_disabled', $account, ['platform' => $account->platform]);
        });
    }

    /** Ask the platform to deliver the Page's events here; records the result. */
    public function subscribe(SocialAccount $account, ?CommentSource $source = null): CommentSource
    {
        $source ??= CommentSource::query()->firstOrCreate(['account_id' => $account->id]);

        try {
            $this->gateway->call($account, fn ($provider, $credentials) => $provider->subscribe($credentials));
            $source->fill(['webhook_state' => CommentSource::SUBSCRIBED, 'webhook_checked_at' => now(), 'last_error_code' => null]);
        } catch (CommentActionFailed $failure) {
            $source->fill(['webhook_state' => CommentSource::FAILED, 'webhook_checked_at' => now(), 'last_error_code' => $failure->errorCode]);
        }

        $source->save();

        return $source;
    }

    /** Whether this account is the one that owns its Page's comment events. */
    public function owns(SocialAccount $account): bool
    {
        return PageClaim::query()
            ->where('platform', $account->platform)->where('external_id', $account->external_id)
            ->where('account_id', $account->id)->where('tenant_id', $this->context->requireId())
            ->exists();
    }

    public function supports(SocialAccount $account): bool
    {
        return $this->providers->for($account->platform)?->available() === true;
    }

    /**
     * Claim the account's Page for this workspace (one workspace per Page, enforced by a unique index). Also used by
     * direct-message capture, which routes through the same claim (ADR 0031), with its own refusal text.
     *
     * @throws ValidationException when another workspace's connected account holds the Page
     */
    public function claim(SocialAccount $account, ?string $refusal = null): void
    {
        $refusal ??= __('This Page already receives comments in another workspace. A Page can feed only one workspace.');
        $tenantId = $this->context->requireId();
        $existing = PageClaim::query()->where('platform', $account->platform)->where('external_id', $account->external_id)->first();

        if ($existing !== null && $existing->account_id === $account->id) {
            return;
        }

        if ($existing !== null) {
            // A claim whose account was disconnected no longer feeds anything: it can be taken over. The lookup is
            // pinned to the claim's own tenant and account, never a bare read of another workspace's table.
            $status = DB::table('social_accounts')->where('tenant_id', $existing->tenant_id)->where('id', $existing->account_id)->value('status');

            if ($status !== null && $status !== AccountStatus::Disconnected->value) {
                throw ValidationException::withMessages(['account' => $refusal]);
            }

            $existing->delete();
        }

        try {
            PageClaim::query()->create([
                'tenant_id' => $tenantId,
                'account_id' => $account->id,
                'platform' => $account->platform,
                'external_id' => $account->external_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['account' => $refusal]);
        }
    }
}

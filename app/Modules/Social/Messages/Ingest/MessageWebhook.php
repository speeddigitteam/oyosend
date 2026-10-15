<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Ingest;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Comments\Ingest\WebhookReceiver;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Messages\Actions\MessageIngestor;
use App\Modules\Social\Messages\Data\MessagingEvent;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\FacebookMessagingWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The messaging half of the one Facebook webhook (ADR 0031). Called by {@see WebhookReceiver} only AFTER the
 * signature over the raw bytes was verified and the body parsed, on the workspace host and on the central host alike.
 *
 * An event is applied only when the Page is claimed by exactly one account (`social_comment_page_claims`; for an
 * Instagram event the claim that carries the Instagram account id) and that account's workspace is the one that was
 * asked (workspace host) or, on the central host, the one the claim names; the account's message capture must be on.
 * Unclaimed Pages, unknown Instagram ids and other workspaces' Pages are ignored without a hint. An event that keeps
 * failing is given up on after a cap, logged with ids and the error class only (never text or a token).
 */
final class MessageWebhook
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly MessageIngestor $ingestor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the parsed, signature-verified body
     * @param  string|null  $onlyTenantId  the workspace the delivery arrived at; null on the central host
     * @return bool true when an event failed and the platform should redeliver
     */
    public function receive(array $payload, ?string $onlyTenantId): bool
    {
        $platforms = MessagingAvailability::platforms();

        if ($platforms === []) {
            return false;
        }

        $retry = false;

        foreach (FacebookMessagingWebhook::events($payload, $platforms, max(1, (int) config('social.messaging.max_events', 200))) as $event) {
            $claim = PageClaim::query()
                ->where('platform', 'facebook')
                ->where($event->platform === DirectConversation::INSTAGRAM ? 'instagram_id' : 'external_id', $event->entryId)
                ->first();

            // Unclaimed, or claimed by another workspace than the one asked: ignored, and nothing is disclosed.
            if ($claim === null || ($onlyTenantId !== null && $claim->tenant_id !== $onlyTenantId)) {
                continue;
            }

            if ($onlyTenantId !== null) {
                $retry = $this->guarded($claim, $event) || $retry;

                continue;
            }

            $tenant = Tenant::query()->find($claim->tenant_id);

            if ($tenant !== null) {
                $retry = $this->context->run($tenant, function () use ($claim, $event): bool {
                    return $this->guarded($claim, $event);
                }) || $retry;
            }
        }

        return $retry;
    }

    /** @return bool true when the event failed and the platform should redeliver it */
    private function guarded(PageClaim $claim, MessagingEvent $event): bool
    {
        try {
            $this->apply($claim, $event);

            return false;
        } catch (Throwable $exception) {
            $key = 'social:dm-webhook-failures:'.$claim->tenant_id.':'.$event->key;
            Cache::add($key, 0, now()->addDay());
            $failures = (int) Cache::increment($key);

            if ($failures < max(1, (int) config('social_comments.webhook.max_failures', 5))) {
                return true;
            }

            // Given up on: answered as handled so the platform stops. Ids and the error class only, never the text.
            Log::error('A direct-message webhook event was given up on after repeated failures.', [
                'tenant_id' => $claim->tenant_id,
                'account_id' => $claim->account_id,
                'platform' => $event->platform,
                'kind' => $event->kind,
                'failures' => $failures,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function apply(PageClaim $claim, MessagingEvent $event): void
    {
        if (! $this->entitlements->allows($this->context->require(), 'social')) {
            return;
        }

        $account = SocialAccount::query()->find($claim->account_id);
        $setting = $account === null ? null : MessagingSetting::query()->where('social_account_id', $account->id)->where('is_enabled', true)->first();

        if ($account === null || $setting === null || $account->status === AccountStatus::Disconnected || $account->external_id !== $claim->external_id) {
            return;
        }

        $routed = $event->platform === DirectConversation::INSTAGRAM
            ? $setting->capturesInstagram() && $setting->instagram_account_id === $event->entryId
            : $account->external_id === $event->entryId;

        if (! $routed) {
            return;
        }

        $this->ingestor->apply($account, $event);
        $setting->forceFill(['last_event_at' => now()])->save();
    }
}

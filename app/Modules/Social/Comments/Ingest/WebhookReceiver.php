<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Ingest;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Comments\Data\CommentEvent;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Models\PageClaim;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Messages\Ingest\MessageWebhook;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\FacebookCommentsWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a verified Facebook Page `feed` delivery into inbox changes, and hands the `messaging` entries of a Page or an
 * Instagram account to {@see MessageWebhook} (direct messages, ADR 0031): a Meta app has one callback URL.
 *
 * Order is the security model: the signature over the raw bytes first; only then is the body parsed; an event is
 * applied only when the Page is claimed by exactly one account and that account's workspace is the one that was
 * asked (the workspace host) or, on the central host, the one the claim names. Everything is deduplicated by the
 * platform's comment id, so a replay changes nothing. An event that keeps failing is given up on after a cap (so the
 * platform stops redelivering a poison event), counted per workspace and event and logged without text.
 */
final class WebhookReceiver
{
    public const int OK = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly CommentIngestor $ingestor,
        private readonly MessageWebhook $messages,
    ) {}

    /**
     * @param  string|null  $onlyTenantId  The workspace the delivery arrived at; null on the central host.
     * @return array{0: int, 1: string} HTTP status and a plain-text body
     */
    public function receive(string $body, ?string $signature, ?string $onlyTenantId): array
    {
        $secret = config('social.platforms.facebook.client_secret');
        $comments = (bool) config('social_comments.enabled', true);

        // The same callback carries Messenger and Instagram direct messages when the operator switched them on.
        if ((! $comments && MessagingAvailability::platforms() === []) || ! is_string($secret) || $secret === '') {
            return [404, 'Not found'];
        }

        if (strlen($body) > (int) config('social_comments.webhook.max_bytes', 262144)) {
            return [413, 'Payload too large'];
        }

        if (! FacebookCommentsWebhook::validSignature($body, $signature, $secret)) {
            return [401, 'Invalid signature'];
        }

        $payload = json_decode($body, true, 16);

        if (! is_array($payload)) {
            return [400, 'Bad request'];
        }

        $retry = false;

        foreach ($comments ? FacebookCommentsWebhook::events($payload, (int) config('social_comments.webhook.max_changes', 200)) : [] as $event) {
            $claim = PageClaim::query()->where('platform', 'facebook')->where('external_id', $event->pageId)->first();

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
                $retry = $this->context->run($tenant, fn (): bool => $this->guarded($claim, $event)) || $retry;
            }
        }

        // `entry[].messaging[]` of a Page (Messenger) or an Instagram account: routed by the same claims (ADR 0031).
        $retry = $this->messages->receive($payload, $onlyTenantId) || $retry;

        return $retry ? [503, 'Try again'] : [self::OK, 'OK'];
    }

    /** @return bool true when the event failed and the platform should redeliver it */
    private function guarded(PageClaim $claim, CommentEvent $event): bool
    {
        try {
            $this->apply($claim, $event);

            return false;
        } catch (Throwable $exception) {
            $key = 'social:comment-webhook-failures:'.$claim->tenant_id.':'.$event->key;
            Cache::add($key, 0, now()->addDay());
            $failures = (int) Cache::increment($key);

            if ($failures < max(1, (int) config('social_comments.webhook.max_failures', 5))) {
                return true;
            }

            // Given up on: answered as handled so the platform stops. Ids and the error class only, never the text.
            Log::error('A comment webhook event was given up on after repeated failures.', [
                'tenant_id' => $claim->tenant_id,
                'account_id' => $claim->account_id,
                'comment_id' => $event->comment->id,
                'verb' => $event->verb,
                'failures' => $failures,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function apply(PageClaim $claim, CommentEvent $event): void
    {
        if (! $this->entitlements->allows($this->context->require(), 'social')) {
            return;
        }

        $account = SocialAccount::query()->find($claim->account_id);
        $source = $account === null ? null : CommentSource::query()->where('account_id', $account->id)->where('is_enabled', true)->first();

        if ($account === null || $source === null || $account->status !== AccountStatus::Connected || $account->external_id !== $event->pageId) {
            return;
        }

        match ($event->verb) {
            'add', 'edited' => $this->ingestor->store($account, $event->comment, 'webhook'),
            'remove' => $this->ingestor->markDeleted($account, $event->comment->id),
            'hide' => $this->ingestor->markHidden($account, $event->comment->id, true),
            'unhide' => $this->ingestor->markHidden($account, $event->comment->id, false),
            default => null,
        };

        $source->forceFill(['last_event_at' => now()])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Listeners;

use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\SmsMarketing\Actions\RecipientStatusApplier;
use App\Modules\SmsMarketing\Models\Recipient;

/**
 * Delivery status from the communication platform (and, through it, from provider webhooks).
 *
 * It listens to the core `communication.marketing.message_status_changed` event, ignores everything that is
 * not an `sms_marketing` message of the active workspace, and lets {@see RecipientStatusApplier} decide what
 * the new state means (a repeated or older answer changes nothing).
 */
final class ApplyMarketingStatus
{
    public const string MODULE = 'sms_marketing';

    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
        private readonly RecipientStatusApplier $applier,
    ) {}

    public function handle(MarketingMessageStatusChanged $event): void
    {
        if ($event->module() !== self::MODULE || ! $this->context->check() || $this->context->id() !== $event->tenantId()) {
            return;
        }

        if (! $this->entitlements->allows($this->context->require(), self::MODULE)) {
            return;
        }

        $payload = $event->payload();
        $recipientId = $payload['recipient_id'] ?? null;
        $messageId = $payload['message_id'] ?? null;

        if (! is_string($recipientId) || $recipientId === '' || ! is_string($messageId)) {
            return;
        }

        $recipient = Recipient::query()->find($recipientId, ['id', 'campaign_id', 'message_id']);

        // A late answer about an earlier attempt must not touch the attempt in progress.
        if ($recipient === null || ($recipient->message_id !== null && $recipient->message_id !== $messageId)) {
            return;
        }

        $failure = $payload['failure_code'] ?? null;
        $this->applier->apply($recipient->id, $event->state(), is_string($failure) ? $failure : null);
    }
}

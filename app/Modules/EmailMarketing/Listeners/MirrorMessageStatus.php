<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Listeners;

use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Actions\DeliverySender;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Delivery;
use Carbon\CarbonImmutable;

/**
 * Mirrors the platform's delivery outcome of our messages onto the delivery rows, and a bounce onto the
 * membership of the contact. Only events of this module are handled (the platform tells us by module key), and
 * only for the workspace that is active. A state never moves backwards (a `delivered` is not taken back to `sent`).
 */
final class MirrorMessageStatus
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly SubscriberManager $subscribers,
    ) {}

    public function handle(MarketingMessageStatusChanged $event): void
    {
        if ($event->module() !== DeliverySender::MODULE
            || ! $this->context->check()
            || $this->context->id() !== $event->tenantId()
            || ! $this->entitlements->allows($this->context->require(), DeliverySender::MODULE)) {
            return;
        }

        $payload = $event->payload();
        $deliveryId = $payload['recipient_id'] ?? null;

        if (! is_string($deliveryId) || $deliveryId === '') {
            return;
        }

        $delivery = Delivery::query()->find($deliveryId);

        if ($delivery === null) {
            return;
        }

        $status = $this->map($event->state());

        if ($status === null || $this->rank($status) < $this->rank($delivery->status)) {
            return;
        }

        $now = CarbonImmutable::now();
        $attributes = ['status' => $status->value, 'updated_at' => $now];

        if (is_string($payload['message_id'] ?? null) && $delivery->message_id === null) {
            $attributes['message_id'] = $payload['message_id'];
        }

        if (in_array($status, [DeliveryStatus::Sent, DeliveryStatus::Delivered], true) && $delivery->sent_at === null) {
            $attributes['sent_at'] = $now;
        }

        if ($status === DeliveryStatus::Delivered) {
            $attributes['delivered_at'] = $now;
        }

        if (in_array($status, [DeliveryStatus::Failed, DeliveryStatus::Bounced], true)) {
            $attributes['failed_at'] = $now;
        }

        if (is_string($payload['failure_code'] ?? null) && $payload['failure_code'] !== '') {
            $attributes['reason'] = mb_substr($payload['failure_code'], 0, 120);
        }

        Delivery::query()->whereKey($delivery->id)->update($attributes);

        if ($status === DeliveryStatus::Bounced) {
            $this->subscribers->mirror($delivery->contact_id, SubscriptionStatus::Bounced, 'bounced');
        }
    }

    private function map(DeliveryState $state): ?DeliveryStatus
    {
        return match ($state) {
            DeliveryState::Sent => DeliveryStatus::Sent,
            DeliveryState::Delivered => DeliveryStatus::Delivered,
            DeliveryState::Failed => DeliveryStatus::Failed,
            DeliveryState::Bounced => DeliveryStatus::Bounced,
            DeliveryState::Blocked => DeliveryStatus::Blocked,
            DeliveryState::Cancelled => DeliveryStatus::Cancelled,
            default => null,
        };
    }

    private function rank(DeliveryStatus $status): int
    {
        return match ($status) {
            DeliveryStatus::Pending, DeliveryStatus::Held, DeliveryStatus::Sending, DeliveryStatus::Queued => 0,
            DeliveryStatus::Sent => 1,
            DeliveryStatus::Delivered => 2,
            default => 3,
        };
    }
}

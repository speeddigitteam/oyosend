<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\SendReceipt;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\SendResult;
use Carbon\CarbonImmutable;

/**
 * Hands ONE delivery to the platform gateway. It is safe to call twice: the claim is atomic (`pending` to
 * `sending`) and the gateway call is idempotent on the delivery's send key, so a retried job, a second worker
 * or a re-run of the runner can never put a second email out.
 *
 * The platform decides consent, suppression, quiet hours, rate limits and metering; this class only prepares the
 * message and records the receipt. It never touches a mailer.
 */
final class DeliverySender
{
    public const string MODULE = 'email_marketing';

    public function __construct(
        private readonly ChannelGateway $gateway,
        private readonly UnsubscribeLinks $links,
        private readonly ContentComposer $composer,
        private readonly EmailMarketingSettings $settings,
        private readonly TenantContext $context,
    ) {}

    public function send(Delivery $delivery, Campaign|SequenceStep $source): SendResult
    {
        $claimed = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->update(['status' => DeliveryStatus::Sending->value, 'attempts' => $delivery->attempts + 1, 'updated_at' => CarbonImmutable::now()]);

        if ($claimed === 0) {
            return new SendResult(null);
        }

        $delivery->refresh();

        $subscription = $delivery->subscription_id === null ? null : Subscription::query()->find($delivery->subscription_id);

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Subscribed) {
            return $this->finish($delivery, DeliveryStatus::Skipped, 'not_subscribed');
        }

        $contact = Contact::query()->with('company')->find($delivery->contact_id);

        if ($contact === null) {
            return $this->finish($delivery, DeliveryStatus::Skipped, 'unknown_contact');
        }

        $availability = $this->gateway->availability(MarketingChannel::Email);

        if (! $availability->isAvailable()) {
            return $this->release($delivery, 'channel_unavailable');
        }

        $unsubscribeUrl = $this->links->issue($contact->id, MarketingChannel::Email, self::MODULE);

        if ($unsubscribeUrl === null) {
            // No address, or the contact vanished from the platform: nothing may be sent without the link.
            return $this->finish($delivery, DeliveryStatus::Skipped, 'no_unsubscribe_link');
        }

        [$subject, $blocks, $preheader, $track] = $this->content($delivery, $source);

        $email = $this->composer->forContact($blocks, $subject, $preheader, $contact, $unsubscribeUrl, $delivery, $track);

        if ($email->subject === '' || strlen($email->text) > 20000) {
            return $this->finish($delivery, DeliveryStatus::Skipped, 'content_invalid');
        }

        $receipt = $this->gateway->send(new MarketingMessage(
            module: self::MODULE,
            idempotencyKey: $delivery->send_key,
            channel: MarketingChannel::Email,
            contactId: $contact->id,
            subject: $email->subject,
            body: $email->text,
            campaignId: $source instanceof Campaign ? $source->id : $source->sequence_id,
            recipientId: $delivery->id,
            locale: $this->context->require()->locale,
        ));

        return $this->record($delivery, $receipt);
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>, 2: string|null, 3: bool}
     */
    private function content(Delivery $delivery, Campaign|SequenceStep $source): array
    {
        if ($source instanceof SequenceStep) {
            return [$source->subject, $source->blocks, $source->preheader, $this->settings->current()->track_clicks];
        }

        $subject = $delivery->variant === 'b' && $source->ab_enabled && $source->ab_subject_b !== null ? $source->ab_subject_b : $source->subject;

        return [$subject, $source->blocks, $source->preheader, $source->track_clicks];
    }

    private function record(Delivery $delivery, SendReceipt $receipt): SendResult
    {
        $state = $receipt->state;

        if ($state === DeliveryState::Unavailable) {
            if (in_array($receipt->reason, ['unknown_contact', 'no_address'], true)) {
                return $this->finish($delivery, DeliveryStatus::Skipped, $receipt->reason);
            }

            return $this->release($delivery, $receipt->reason ?? 'channel_unavailable');
        }

        if ($state === DeliveryState::Failed && $receipt->reason === 'limit_reached') {
            return $this->release($delivery, 'limit_reached');
        }

        $status = match ($state) {
            DeliveryState::Queued, DeliveryState::Sending => DeliveryStatus::Queued,
            DeliveryState::Sent => DeliveryStatus::Sent,
            DeliveryState::Delivered => DeliveryStatus::Delivered,
            DeliveryState::Failed => DeliveryStatus::Failed,
            DeliveryState::Bounced => DeliveryStatus::Bounced,
            DeliveryState::Blocked => DeliveryStatus::Blocked,
            default => DeliveryStatus::Cancelled,
        };

        // The platform's status events may already have moved this row (the test queue is synchronous), so only
        // a row that is still `sending` is written; a later state is never taken back.
        $updated = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Sending->value)
            ->update([
                'status' => $status->value,
                'reason' => $receipt->reason,
                'message_id' => $receipt->messageId,
                'sent_at' => $status->wasSent() ? CarbonImmutable::now() : null,
                'failed_at' => in_array($status, [DeliveryStatus::Failed, DeliveryStatus::Bounced], true) ? CarbonImmutable::now() : null,
                'updated_at' => CarbonImmutable::now(),
            ]);

        if ($updated === 0 && $receipt->messageId !== null) {
            Delivery::query()->whereKey($delivery->id)->whereNull('message_id')->update(['message_id' => $receipt->messageId]);
        }

        return new SendResult($status);
    }

    private function finish(Delivery $delivery, DeliveryStatus $status, ?string $reason): SendResult
    {
        Delivery::query()->whereKey($delivery->id)->where('status', DeliveryStatus::Sending->value)->update([
            'status' => $status->value,
            'reason' => $reason,
            'updated_at' => CarbonImmutable::now(),
        ]);

        return new SendResult($status);
    }

    /** Put the delivery back so a later run sends it, and ask the caller to stop. */
    private function release(Delivery $delivery, string $reason): SendResult
    {
        Delivery::query()->whereKey($delivery->id)->where('status', DeliveryStatus::Sending->value)->update([
            'status' => DeliveryStatus::Pending->value,
            'attempts' => max(0, $delivery->attempts - 1),
            'updated_at' => CarbonImmutable::now(),
        ]);

        return new SendResult(null, $reason);
    }
}

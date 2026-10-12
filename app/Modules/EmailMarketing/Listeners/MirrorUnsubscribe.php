<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Actions\DeliverySender;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;

/**
 * The Email Marketing end of `communication.contact.unsubscribed`.
 *
 * The communication app announces it when a contact withdrew marketing consent on a channel (unsubscribe link, by
 * hand, or a complaint). On the email channel every open membership of that contact is closed here, so lists,
 * segments and sequences stop counting them at once. It reads only the event's documented payload and never
 * touches the communication app's models; the event class is referenced by name, as a documented hand-off.
 */
final class MirrorUnsubscribe
{
    public const string EVENT = 'communication.contact.unsubscribed';

    /** Class of the event, referenced by name so this module does not import another module's classes. */
    public const string EVENT_CLASS = 'App\\Modules\\Communication\\Events\\ContactUnsubscribed';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly SubscriberManager $subscribers,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if ($event::name() !== self::EVENT || ! $this->context->check() || $this->context->id() !== $event->tenantId()) {
            return;
        }

        if (! $this->entitlements->allows($this->context->require(), DeliverySender::MODULE)) {
            return;
        }

        $payload = $event->payload();
        $contactId = $payload['contact_id'] ?? null;

        if (($payload['channel'] ?? null) !== 'email' || ! is_string($contactId) || $contactId === '') {
            return;
        }

        $complaint = ($payload['method'] ?? null) === 'complaint';

        $this->subscribers->mirror(
            $contactId,
            $complaint ? SubscriptionStatus::Complained : SubscriptionStatus::Unsubscribed,
            $complaint ? 'complaint' : 'platform_unsubscribe',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\ChannelEvent;

/**
 * An app that wants to hear provider events the platform itself only partly uses: inbound customer messages,
 * read receipts and template approval changes.
 *
 * Register an implementation in your own service provider by tagging it
 * (`$this->app->tag([MyListener::class], ChannelEventListener::TAG)`); import nothing else of the
 * communication app. Listeners run in-process inside the (stateless, throttled) webhook request, inside the
 * workspace's tenant context, after the platform applied the event. A listener must:
 *
 *  - check its own entitlement (the platform's webhook route only requires `communication`);
 *  - be idempotent and fast (the platform de-duplicates by provider event id, but a crash can replay);
 *  - never throw for an event it does not care about. An exception is caught and logged without content, so
 *    one app can never break another's delivery tracking. The one exception: when a listener throws for an
 *    INBOUND message the webhook answers 5xx and nothing of the event is kept, so the provider redelivers it
 *    (an inbound message exists nowhere else). Receipts and template status are never retried that way;
 *  - queue follow-up work only after commit (`dispatch(...)->afterCommit()`): the event is applied inside one
 *    transaction that rolls back when any listener fails, and the redelivery runs every listener again, so work
 *    queued eagerly would run once per attempt;
 *  - treat `text` and `details` as untrusted input.
 *
 * The webhook processes every event of a payload even when one fails. After `communication.webhook_max_failures`
 * failed deliveries of the same event it is recorded as failed and not retried again.
 */
interface ChannelEventListener
{
    /** Container tag under which listeners are registered. */
    public const string TAG = 'communication.channel_event_listeners';

    public function handle(ChannelEvent $event): void;
}

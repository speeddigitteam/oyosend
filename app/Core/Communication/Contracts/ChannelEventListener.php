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
 *  - never throw for an event it does not care about. Exceptions are caught and logged without content, so
 *    one app can never break delivery tracking for another;
 *  - treat `text` and `details` as untrusted input.
 */
interface ChannelEventListener
{
    /** Container tag under which listeners are registered. */
    public const string TAG = 'communication.channel_event_listeners';

    public function handle(ChannelEvent $event): void;
}

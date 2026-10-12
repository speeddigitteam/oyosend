<?php

declare(strict_types=1);

use App\Core\Billing\UsageMeter;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\CommunicationSettings;
use App\Modules\Communication\Actions\MessageDispatcher;
use App\Modules\Communication\Actions\OutboundRequest;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Events\MessageFailed;
use App\Modules\Communication\Events\MessageSent;
use App\Modules\Communication\Jobs\DispatchDueMessages;
use App\Modules\Communication\Jobs\SendMessage;
use App\Modules\Communication\Mail\OutboundMail;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Testing\InMemoryProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-out');
    $this->queue = fn (array $overrides = []) => $this->inTenant($this->tenant, fn () => app(Outbox::class)->queue(new OutboundRequest(...[
        'channel' => Channel::Email,
        'purpose' => MessagePurpose::Transactional,
        'recipient' => 'Customer@Example.test',
        'subject' => 'Your order',
        'body' => 'It is on its way.',
        'idempotencyKey' => 'order-1',
        ...$overrides,
    ])));
    $this->fresh = fn (Message $message) => $this->inTenant($this->tenant, fn () => $message->fresh());
});

it('sends transactional email through the mailer and meters it once', function () {
    Mail::fake();
    Event::fake([MessageSent::class]);

    $message = ($this->queue)();
    $stored = ($this->fresh)($message);

    expect($stored->status)->toBe(MessageStatus::Sent)
        ->and($stored->recipient)->toBe('customer@example.test')
        ->and($stored->provider)->toBe('mail')
        ->and($stored->attempts)->toBe(1)
        ->and($stored->sent_at)->not->toBeNull();
    Mail::assertSent(OutboundMail::class, fn (OutboundMail $mail) => $mail->hasTo('customer@example.test'));
    Event::assertDispatched(MessageSent::class);

    $used = $this->inTenant($this->tenant, fn () => app(UsageMeter::class)->used('communication.messages'));
    expect($used)->toBe(1);
});

it('queues the same intent once and returns the original row', function () {
    Mail::fake();

    $first = ($this->queue)();
    $second = ($this->queue)(['body' => 'A different body that must be ignored']);

    expect($second->id)->toBe($first->id)
        ->and($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(1);
    Mail::assertSentCount(1);
});

it('does not send twice when the job is delivered twice', function () {
    Mail::fake();
    $message = ($this->queue)();

    $this->inTenant($this->tenant, function () use ($message): void {
        SendMessage::dispatchSync($message->id);
        SendMessage::dispatchSync($message->id);
    });

    Mail::assertSentCount(1);
    expect(($this->fresh)($message)->attempts)->toBe(1);
});

it('refuses an address on the suppression list and records why', function () {
    Mail::fake();
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(
        Channel::Email, 'customer@example.test', SuppressionScope::All, SuppressionReason::Bounced,
    ));

    $message = ($this->queue)();

    expect($message->status)->toBe(MessageStatus::Suppressed)
        ->and($message->failure_code)->toBe('suppressed');
    Mail::assertNothingSent();
});

it('lets a marketing unsubscribe block marketing but not transactional mail', function () {
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'customer@example.test']);
    $this->optIn($this->tenant, $contact);
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(
        Channel::Email, 'customer@example.test', SuppressionScope::Marketing, SuppressionReason::Unsubscribed, $contact->id,
    ));

    $marketing = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id, 'idempotencyKey' => 'm-1']);
    $service = ($this->queue)(['idempotencyKey' => 't-1', 'contactId' => $contact->id]);

    expect($marketing->status)->toBe(MessageStatus::Suppressed)
        ->and(($this->fresh)($service)->status)->toBe(MessageStatus::Sent);
});

it('sends marketing only with recorded opt-in', function () {
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'customer@example.test']);

    $without = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id, 'idempotencyKey' => 'm-1']);
    $anonymous = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'idempotencyKey' => 'm-2']);
    $this->optIn($this->tenant, $contact);
    $with = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id, 'idempotencyKey' => 'm-3']);

    expect($without->failure_code)->toBe('no_consent')
        ->and($anonymous->failure_code)->toBe('no_consent')
        ->and(($this->fresh)($with)->status)->toBe(MessageStatus::Sent);
    Mail::assertSentCount(1);
});

it('re-checks consent at worker time, after the contact opted out in between', function () {
    Mail::fake();
    Queue::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'customer@example.test']);
    $this->optIn($this->tenant, $contact);

    $message = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id]);
    expect($message->status)->toBe(MessageStatus::Queued);

    $this->inTenant($this->tenant, fn () => Consent::query()->update(['status' => 'opted_out']));
    $this->inTenant($this->tenant, fn () => app(MessageDispatcher::class)->deliver($message->id));

    expect(($this->fresh)($message)->status)->toBe(MessageStatus::Suppressed);
    Mail::assertNothingSent();
});

it('holds marketing during quiet hours but never transactional messages', function () {
    Queue::fake();
    Mail::fake();
    $contact = $this->commContact($this->tenant, ['email' => 'customer@example.test']);
    $this->optIn($this->tenant, $contact);
    $this->inTenant($this->tenant, fn () => app(CommunicationSettings::class)->update([
        'quiet_hours_enabled' => true, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00',
    ]));

    // 23:30 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-03-02 23:30', 'Asia/Dhaka'));

    $marketing = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id, 'idempotencyKey' => 'q-1']);
    $service = ($this->queue)(['idempotencyKey' => 'q-2']);

    expect($marketing->status)->toBe(MessageStatus::Queued)
        ->and($marketing->next_attempt_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-03 08:00')
        ->and($service->next_attempt_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-02 23:30');

    // At worker time inside the window nothing is sent and the message waits for the end of it.
    $this->inTenant($this->tenant, fn () => app(MessageDispatcher::class)->deliver($marketing->id));
    expect(($this->fresh)($marketing)->status)->toBe(MessageStatus::Queued);
    Mail::assertNothingSent();

    // Before the window began (20:00) the same marketing message is not held back.
    $this->travelTo(CarbonImmutable::parse('2026-03-02 20:00', 'Asia/Dhaka'));
    $early = ($this->queue)(['purpose' => MessagePurpose::Marketing, 'contactId' => $contact->id, 'idempotencyKey' => 'q-3']);
    expect($early->next_attempt_at->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('20:00');
});

it('counts a message against the plan limit and refuses the manual composer at it', function () {
    Mail::fake();
    $plan = $this->createFreePlan(['limits' => ['communication.messages' => 1]]);
    $this->subscribe($this->tenant, $plan);

    $first = ($this->queue)(['idempotencyKey' => 'l-1']);
    $second = ($this->queue)(['idempotencyKey' => 'l-2']);

    expect(($this->fresh)($first)->status)->toBe(MessageStatus::Sent)
        ->and($second->status)->toBe(MessageStatus::Failed)
        ->and($second->failure_code)->toBe('limit_reached');

    expect(fn () => $this->inTenant($this->tenant, fn () => app(Outbox::class)->assertWithinLimit()))
        ->toThrow(ValidationException::class);
});

it('retries a temporary failure with backoff and fails a permanent one', function () {
    $this->connectFakeProvider($this->tenant);
    Event::fake([MessageFailed::class]);

    $temporary = ($this->queue)(['channel' => Channel::Sms, 'recipient' => '+8801711000000', 'subject' => null, 'body' => 'FAIL_TEMPORARY now', 'idempotencyKey' => 's-1']);
    $stored = ($this->fresh)($temporary);

    expect($stored->status)->toBe(MessageStatus::Queued)
        ->and($stored->attempts)->toBe(1)
        ->and($stored->failure_code)->toBe('provider_unavailable')
        ->and($stored->next_attempt_at->isFuture())->toBeTrue();

    $permanent = ($this->queue)(['channel' => Channel::Sms, 'recipient' => '+8801711000000', 'subject' => null, 'body' => 'FAIL_PERMANENT', 'idempotencyKey' => 's-2']);

    expect(($this->fresh)($permanent)->status)->toBe(MessageStatus::Failed);
    Event::assertDispatched(MessageFailed::class);
    // A recipient the provider calls invalid is suppressed so it is not tried again.
    expect($this->inTenant($this->tenant, fn () => app(Outbox::class)->blockedReason(Channel::Sms, MessagePurpose::Transactional, '+8801711000000', null)))
        ->toBe('suppressed');
});

it('stops retrying after the maximum number of attempts', function () {
    $this->connectFakeProvider($this->tenant);
    config(['communication.max_attempts' => 2]);

    $message = ($this->queue)(['channel' => Channel::Sms, 'recipient' => '+8801711000000', 'subject' => null, 'body' => 'FAIL_TEMPORARY', 'idempotencyKey' => 's-1']);

    // Make the retry due and let the scheduler's job pick it up.
    $this->inTenant($this->tenant, fn () => Message::query()->update(['next_attempt_at' => now()->subMinute(), 'dispatched_at' => null]));
    $this->inTenant($this->tenant, fn () => (new DispatchDueMessages)->handle(app(Outbox::class)));

    $stored = ($this->fresh)($message);
    expect($stored->status)->toBe(MessageStatus::Failed)->and($stored->attempts)->toBe(2);
});

it('reports SMS honestly as not configured and refuses to send', function () {
    $message = ($this->queue)(['channel' => Channel::Sms, 'recipient' => '+8801711000000', 'subject' => null, 'body' => 'Hello', 'idempotencyKey' => 's-1']);
    $stored = ($this->fresh)($message);

    expect($stored->status)->toBe(MessageStatus::Failed)
        ->and($stored->failure_code)->toBe('provider_not_configured')
        ->and(InMemoryProvider::$sent)->toBe([]);
});

it('cancels a queued message when the app is no longer entitled at worker time', function () {
    Queue::fake();
    Mail::fake();
    $message = ($this->queue)();
    expect($message->status)->toBe(MessageStatus::Queued);

    $this->app->instance(EntitlementChecker::class, new class implements EntitlementChecker
    {
        public function allows(Tenant $tenant, string $moduleKey): bool
        {
            return false;
        }

        public function entitledModules(Tenant $tenant): array
        {
            return [];
        }
    });
    $this->app->forgetScopedInstances();

    $this->inTenant($this->tenant, fn () => app(MessageDispatcher::class)->deliver($message->id));

    expect(($this->fresh)($message)->status)->toBe(MessageStatus::Cancelled)
        ->and(($this->fresh)($message)->failure_code)->toBe('module_not_entitled');
    Mail::assertNothingSent();
});

it('limits a channel to its configured messages per minute', function () {
    Mail::fake();
    Queue::fake();
    config(['communication.rate_limits.email' => 2]);

    $this->inTenant($this->tenant, function (): void {
        $dispatcher = app(MessageDispatcher::class);
        $outbox = app(Outbox::class);
        $ids = [];

        foreach (['r-1', 'r-2', 'r-3'] as $key) {
            $ids[] = $outbox->queue(new OutboundRequest(
                Channel::Email, MessagePurpose::Transactional, 'customer@example.test', 'Hi', 'Body', $key,
            ))->id;
        }

        foreach ($ids as $id) {
            $dispatcher->deliver($id);
        }

        expect(Message::query()->where('status', MessageStatus::Sent->value)->count())->toBe(2)
            ->and(Message::query()->where('status', MessageStatus::Queued->value)->count())->toBe(1);
    });
});

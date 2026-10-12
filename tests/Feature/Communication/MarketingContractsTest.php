<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Contracts\UnsubscribeLinks;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\ChannelState;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Core\Communication\NullChannelGateway;
use App\Core\Communication\NullMarketingAudience;
use App\Core\Communication\NullUnsubscribeLinks;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Actions\MarketingAudienceResolver;
use App\Modules\Communication\Actions\MarketingGateway;
use App\Modules\Communication\Actions\MarketingUnsubscribeLinks;
use App\Modules\Communication\Actions\MessageDispatcher;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\UnsubscribeToken;
use App\Modules\Communication\Testing\InMemoryProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-mkt');
    $this->contact = $this->commContact($this->tenant, ['email' => 'buyer@example.test', 'phone' => '+8801711223344']);
    $this->audience = fn () => app(MarketingAudience::class);
    $this->gateway = fn () => app(ChannelGateway::class);
    $this->message = fn (array $overrides = []) => new MarketingMessage(...[
        'module' => 'email_marketing',
        'idempotencyKey' => 'campaign:c1:recipient:r1',
        'channel' => MarketingChannel::Email,
        'contactId' => $this->contact->id,
        'subject' => 'Big sale',
        'body' => 'Everything is cheaper.',
        'campaignId' => 'c1',
        'recipientId' => 'r1',
        ...$overrides,
    ]);
});

it('resolves the contracts to the module implementations', function () {
    $this->inTenant($this->tenant, function (): void {
        expect(app(MarketingAudience::class))->toBeInstanceOf(MarketingAudienceResolver::class)
            ->and(app(ChannelGateway::class))->toBeInstanceOf(MarketingGateway::class)
            ->and(app(UnsubscribeLinks::class))->toBeInstanceOf(MarketingUnsubscribeLinks::class);
    });
});

it('treats a missing consent record as no marketing, and blocks the send but records it', function () {
    Mail::fake();

    $this->inTenant($this->tenant, function (): void {
        $decision = ($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email);
        expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe(AudienceReason::NoConsent);

        $receipt = ($this->gateway)()->send(($this->message)());

        expect($receipt->state)->toBe(DeliveryState::Blocked)
            ->and($receipt->reason)->toBe('no_consent')
            ->and($receipt->messageId)->not->toBeNull()
            ->and(Message::query()->sole()->status)->toBe(MessageStatus::Suppressed);
    });

    Mail::assertNothingSent();
});

it('allows an opted-in contact, sends once through the outbox and reports the status', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);

    $this->inTenant($this->tenant, function (): void {
        $decision = ($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email);
        expect($decision->allowed)->toBeTrue()->and($decision->reason)->toBe(AudienceReason::Ok);

        $receipt = ($this->gateway)()->send(($this->message)());
        $stored = Message::query()->sole();

        expect($receipt->state)->toBe(DeliveryState::Queued)
            ->and($receipt->replayed)->toBeFalse()
            ->and($receipt->messageId)->toBe($stored->id)
            ->and($stored->source)->toBe('email_marketing')
            ->and($stored->idempotency_key)->toBe('email_marketing:campaign:c1:recipient:r1')
            ->and($stored->purpose->value)->toBe('marketing');

        // The queue runs synchronously in tests, so the worker already sent it.
        $report = ($this->gateway)()->statusFor($receipt->messageId);
        expect($report?->state)->toBe(DeliveryState::Sent)->and($report?->sentAt)->not->toBeNull();
    });

    Mail::assertSentCount(1);
});

it('never double-sends: the same key replays the original receipt', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);

    $this->inTenant($this->tenant, function (): void {
        $first = ($this->gateway)()->send(($this->message)());
        $second = ($this->gateway)()->send(($this->message)(['body' => 'Changed body']));
        $other = ($this->gateway)()->send(($this->message)(['idempotencyKey' => 'campaign:c1:recipient:r2', 'recipientId' => 'r2']));

        expect($second->replayed)->toBeTrue()
            ->and($second->messageId)->toBe($first->messageId)
            ->and($second->state)->toBe(DeliveryState::Sent)
            ->and($other->messageId)->not->toBe($first->messageId)
            ->and(Message::query()->count())->toBe(2);
    });

    Mail::assertSentCount(2);
});

it('does not let two modules collide on the same idempotency key', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);

    $this->inTenant($this->tenant, function (): void {
        $a = ($this->gateway)()->send(($this->message)());
        $b = ($this->gateway)()->send(($this->message)(['module' => 'sms_marketing']));

        expect($b->replayed)->toBeFalse()->and($b->messageId)->not->toBe($a->messageId);
    });
});

it('blocks a suppressed address and says whether it was a suppression or an unsubscribe', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);

    $this->inTenant($this->tenant, function (): void {
        $suppressions = app(SuppressionManager::class);

        $bounced = $suppressions->suppress(Channel::Email, 'buyer@example.test', SuppressionScope::All, SuppressionReason::Bounced);
        $decision = ($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email);
        expect($decision->reason)->toBe(AudienceReason::Suppressed);

        // A transactional-purpose question is also blocked by an `all` suppression.
        expect(($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email, MessagingPurpose::Transactional)->allowed)->toBeFalse();

        $receipt = ($this->gateway)()->send(($this->message)());
        expect($receipt->state)->toBe(DeliveryState::Blocked)->and($receipt->reason)->toBe('suppressed');

        app(SuppressionManager::class)->lift($bounced);
        $suppressions->suppress(Channel::Email, 'buyer@example.test', SuppressionScope::Marketing, SuppressionReason::Unsubscribed);

        expect(($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email)->reason)->toBe(AudienceReason::Unsubscribed)
            // A marketing-scope unsubscribe does not stop transactional mail.
            ->and(($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email, MessagingPurpose::Transactional)->allowed)->toBeTrue();

        $second = ($this->gateway)()->send(($this->message)(['idempotencyKey' => 'k2']));
        expect($second->state)->toBe(DeliveryState::Blocked)->and($second->reason)->toBe('unsubscribed');
    });

    Mail::assertNothingSent();
});

it('blocks a contact who opted out', function () {
    $this->inTenant($this->tenant, function (): void {
        $contact = $this->contact->fresh();
        app(ConsentManager::class)->record($contact, Channel::Email, ConsentStatus::OptedOut, 'verbal');

        expect(($this->audience)()->canReceive($contact->id, MarketingChannel::Email)->reason)->toBe(AudienceReason::OptedOut);
    });
});

it('issues a hashed, tenant-host unsubscribe link whose redemption blocks the contact', function () {
    $this->optIn($this->tenant, $this->contact);

    $url = $this->inTenant($this->tenant, fn () => app(UnsubscribeLinks::class)->issue($this->contact->id, MarketingChannel::Email, 'email_marketing'));
    $token = basename((string) $url);

    expect($url)->toStartWith($this->commUrl($this->tenant, '/unsubscribe/'))->and(strlen($token))->toBe(40);

    $this->inTenant($this->tenant, function () use ($token): void {
        $row = UnsubscribeToken::query()->sole();
        expect($row->token_hash)->toBe(hash('sha256', $token))
            ->and($row->message_id)->toBeNull()
            ->and($row->address)->toBe('buyer@example.test')
            ->and(json_encode($row->getAttributes()))->not->toContain($token);
    });

    $this->post($this->commUrl($this->tenant, '/unsubscribe/'.$token))->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $decision = ($this->audience)()->canReceive($this->contact->id, MarketingChannel::Email);
        expect($decision->allowed)->toBeFalse()->and($decision->reason)->toBe(AudienceReason::Unsubscribed);
    });
});

it('returns no unsubscribe link for a contact without an address on the channel', function () {
    $contact = $this->commContact($this->tenant, ['email' => null, 'phone' => null]);

    $this->inTenant($this->tenant, function () use ($contact): void {
        expect(app(UnsubscribeLinks::class)->issue($contact->id, MarketingChannel::Email, 'email_marketing'))->toBeNull()
            ->and(app(UnsubscribeLinks::class)->issue($contact->id, MarketingChannel::Sms, 'sms_marketing'))->toBeNull();
    });
});

it('decides a whole list with a fixed number of queries and every id present', function () {
    $this->optIn($this->tenant, $this->contact);
    $ids = [$this->contact->id];

    for ($i = 0; $i < 12; $i++) {
        $other = $this->commContact($this->tenant);

        if ($i % 2 === 0) {
            $this->optIn($this->tenant, $other);
        }

        $ids[] = $other->id;
    }

    $ids[] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $this->inTenant($this->tenant, function () use ($ids): void {
        // Warm the entitlement and settings lookups, then count.
        ($this->audience)()->decideMany([$ids[0]], MarketingChannel::Email);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $decisions = ($this->audience)()->decideMany($ids, MarketingChannel::Email);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($decisions)->toHaveCount(count($ids))
            ->and($queries)->toBeLessThanOrEqual(6)
            ->and(collect($decisions)->where('allowed', true))->toHaveCount(7)
            ->and($decisions['01ARZ3NDEKTSV4RRFFQ69G5FAV']->reason)->toBe(AudienceReason::UnknownContact);

        expect(fn () => ($this->audience)()->decideMany(array_map(static fn (int $n): string => 'id'.$n, range(1, 1001)), MarketingChannel::Email))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('reports a channel without a provider as not configured, and refuses to record a send', function () {
    $this->optIn($this->tenant, $this->contact, Channel::Sms);

    $this->inTenant($this->tenant, function (): void {
        // The honest default adapter for SMS is never configured.
        ChannelConnection::query()->delete();
        $availability = ($this->gateway)()->availability(MarketingChannel::Sms);

        expect($availability->state)->toBe(ChannelState::NotConfigured)
            ->and($availability->reason)->toBe('provider_not_configured')
            ->and(($this->audience)()->canReceive($this->contact->id, MarketingChannel::Sms)->reason)->toBe(AudienceReason::ChannelUnavailable);

        $receipt = ($this->gateway)()->send(($this->message)(['channel' => MarketingChannel::Sms, 'subject' => null]));

        expect($receipt->state)->toBe(DeliveryState::Unavailable)
            ->and($receipt->messageId)->toBeNull()
            ->and(Message::query()->count())->toBe(0);

        expect(($this->gateway)()->availability(MarketingChannel::Email)->state)->toBe(ChannelState::Available);
    });
});

it('sends SMS through a connected provider and relays delivery as a core event', function () {
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    $this->optIn($this->tenant, $this->contact, Channel::Sms);
    Event::fake([MarketingMessageStatusChanged::class]);

    $sms = ($this->message)(['module' => 'sms_marketing', 'channel' => MarketingChannel::Sms, 'subject' => null, 'body' => 'Sale today']);

    $messageId = $this->inTenant($this->tenant, function () use ($sms): string {
        $availability = ($this->gateway)()->availability(MarketingChannel::Sms);
        expect($availability->isAvailable())->toBeTrue()->and($availability->testDouble)->toBeTrue();

        return (string) ($this->gateway)()->send($sms)->messageId;
    });

    expect(InMemoryProvider::$sent)->toHaveCount(1);
    Event::assertDispatched(MarketingMessageStatusChanged::class, fn (MarketingMessageStatusChanged $e) => $e->state() === DeliveryState::Sent
        && $e->module() === 'sms_marketing'
        && $e->payload()['campaign_id'] === 'c1'
        && $e->payload()['recipient_id'] === 'r1'
        && $e->messageId() === $messageId);

    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'd1', 'type' => 'delivered', 'message_id' => 'fake-'.$messageId]]])->assertOk();

    Event::assertDispatched(MarketingMessageStatusChanged::class, fn (MarketingMessageStatusChanged $e) => $e->state() === DeliveryState::Delivered
        && $e->messageId() === $messageId);

    $report = $this->inTenant($this->tenant, fn () => ($this->gateway)()->statusFor($messageId));
    expect($report?->state)->toBe(DeliveryState::Delivered)->and($report?->deliveredAt)->not->toBeNull();
});

it('does not relay events for messages that did not come through the gateway', function () {
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    Event::fake([MarketingMessageStatusChanged::class]);

    $this->inTenant($this->tenant, function (): void {
        $message = Message::query()->create([
            'channel' => Channel::Sms, 'purpose' => 'marketing', 'status' => MessageStatus::Sent,
            'recipient' => '+8801711223344', 'body' => 'Hi', 'locale' => 'en', 'idempotency_key' => 'manual-1',
            'provider' => 'fake_sms', 'provider_message_id' => 'prov-x', 'contact_id' => $this->contact->id, 'attempts' => 1,
        ]);

        expect(($this->gateway)()->statusFor($message->id))->toBeNull();
    });

    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'd9', 'type' => 'delivered', 'message_id' => 'prov-x']]])->assertOk();

    Event::assertNotDispatched(MarketingMessageStatusChanged::class);
});

it('announces a message suppressed after it was queued', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);
    Event::fake([MarketingMessageStatusChanged::class]);

    $this->inTenant($this->tenant, function (): void {
        // Due in the future so the worker leaves it queued; then the contact unsubscribes before it runs.
        $receipt = ($this->gateway)()->send(($this->message)(['sendAt' => now()->addHour()]));
        expect($receipt->state)->toBe(DeliveryState::Queued)->and($receipt->dueAt)->not->toBeNull();

        app(SuppressionManager::class)->suppress(Channel::Email, 'buyer@example.test', SuppressionScope::Marketing, SuppressionReason::Unsubscribed);
        Message::query()->whereKey($receipt->messageId)->update(['next_attempt_at' => now()->subMinute()]);
        app(MessageDispatcher::class)->deliver((string) $receipt->messageId);
    });

    Event::assertDispatched(MarketingMessageStatusChanged::class, fn (MarketingMessageStatusChanged $e) => $e->state() === DeliveryState::Blocked
        && $e->payload()['failure_code'] === 'suppressed');
    Mail::assertNothingSent();
});

it('answers unavailable, safely, when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('comm-plain');
    $contact = $this->commContact($plain, ['email' => 'x@example.test']);

    $this->inTenant($plain, function () use ($contact): void {
        $availability = app(ChannelGateway::class)->availability(MarketingChannel::Email);
        $decision = app(MarketingAudience::class)->canReceive($contact->id, MarketingChannel::Email);
        $receipt = app(ChannelGateway::class)->send(new MarketingMessage('email_marketing', 'k', MarketingChannel::Email, $contact->id, 'S', 'B'));

        expect($availability->state)->toBe(ChannelState::NotConfigured)->and($availability->reason)->toBe('module_unavailable')
            ->and($decision->allowed)->toBeFalse()->and($decision->reason)->toBe(AudienceReason::ChannelUnavailable)
            ->and($receipt->state)->toBe(DeliveryState::Unavailable)->and($receipt->messageId)->toBeNull()
            ->and(app(UnsubscribeLinks::class)->issue($contact->id, MarketingChannel::Email, 'email_marketing'))->toBeNull();
    });
});

it('ships null objects that refuse everything, for builds without the module', function () {
    $audience = new NullMarketingAudience;
    $gateway = new NullChannelGateway;

    expect($audience->canReceive('c', MarketingChannel::Email)->reason)->toBe(AudienceReason::ChannelUnavailable)
        ->and($audience->decideMany(['a', 'b', 'a'], MarketingChannel::Sms))->toHaveCount(2)
        ->and((new NullUnsubscribeLinks)->issue('c', MarketingChannel::Email, 'email_marketing'))->toBeNull()
        ->and($gateway->availability(MarketingChannel::Email)->isAvailable())->toBeFalse()
        ->and($gateway->send(new MarketingMessage('email_marketing', 'k', MarketingChannel::Sms, 'c', null, 'Hi'))->state)->toBe(DeliveryState::Unavailable)
        ->and($gateway->statusFor('x'))->toBeNull()
        ->and($gateway->statusForMany(['x']))->toBe([]);
});

it('keeps contacts, messages and tokens inside their workspace', function () {
    Mail::fake();
    $this->optIn($this->tenant, $this->contact);
    $other = $this->commTenant('comm-mkt-b');
    $foreign = $this->commContact($other, ['email' => 'buyer@example.test']);
    $this->optIn($other, $foreign);

    $mine = $this->inTenant($this->tenant, fn () => ($this->gateway)()->send(($this->message)()));

    $this->inTenant($other, function () use ($foreign, $mine): void {
        // Another workspace's contact id is unknown here, and its message is invisible.
        expect(app(MarketingAudience::class)->canReceive($this->contact->id, MarketingChannel::Email)->reason)->toBe(AudienceReason::UnknownContact)
            ->and(app(ChannelGateway::class)->statusFor((string) $mine->messageId))->toBeNull()
            ->and(app(ChannelGateway::class)->statusForMany([(string) $mine->messageId]))->toBe([])
            ->and(app(UnsubscribeLinks::class)->issue($this->contact->id, MarketingChannel::Email, 'email_marketing'))->toBeNull();

        $receipt = app(ChannelGateway::class)->send(new MarketingMessage('email_marketing', 'campaign:c1:recipient:r1', MarketingChannel::Email, $this->contact->id, 'S', 'B'));
        expect($receipt->state)->toBe(DeliveryState::Unavailable)->and($receipt->reason)->toBe('unknown_contact');

        // The same idempotency key is its own message in this workspace.
        $own = app(ChannelGateway::class)->send(new MarketingMessage('email_marketing', 'campaign:c1:recipient:r1', MarketingChannel::Email, $foreign->id, 'S', 'B'));
        expect($own->replayed)->toBeFalse()->and($own->messageId)->not->toBe($mine->messageId);
    });
});

it('validates what a module may hand to the gateway', function () {
    expect(fn () => new MarketingMessage('Bad Key', 'k', MarketingChannel::Sms, 'c', null, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MarketingMessage('email_marketing', str_repeat('k', 120), MarketingChannel::Sms, 'c', null, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MarketingMessage('email_marketing', 'k', MarketingChannel::Email, 'c', ' ', 'x'))->toThrow(InvalidArgumentException::class);
});

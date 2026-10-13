<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\ChannelEventType;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\NullChannelNumbers;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\MessageDispatcher;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\AccountClaim;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\ChannelPhoneNumber;
use App\Modules\Communication\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

/** Collects every channel event it hears (ADR 0022 tagging of the receiving number). */
final class NumbersEventCollector implements ChannelEventListener
{
    /** @var list<ChannelEvent> */
    public static array $events = [];

    public function handle(ChannelEvent $event): void
    {
        self::$events[] = $event;
    }
}

function numbersGateway(): ChannelGateway
{
    return app(ChannelGateway::class);
}

function numbersMessage(string $contactId, string $key, ?string $numberId = null, string $body = 'Hello'): MarketingMessage
{
    return new MarketingMessage(
        module: 'whatsapp_marketing',
        idempotencyKey: $key,
        channel: MarketingChannel::Whatsapp,
        contactId: $contactId,
        subject: null,
        body: $body,
        senderNumberId: $numberId,
    );
}

beforeEach(function () {
    NumbersEventCollector::$events = [];
    $this->app->tag([NumbersEventCollector::class], ChannelEventListener::TAG);
    $this->tenant = $this->waTenant('numbers-ws');
    $this->waConnectCloud($this->tenant, '109876543210', '555000111');
    $this->directory = fn () => app(ChannelNumberDirectory::class);
    $this->registry = fn () => app(ChannelNumberRegistry::class);
});

it('gives a single-number workspace one default number named after its connection setting', function () {
    $numbers = $this->inTenant($this->tenant, fn () => ($this->directory)()->numbers(MarketingChannel::Whatsapp));

    expect($numbers)->toHaveCount(1)
        ->and($numbers[0]->accountId)->toBe('109876543210')
        ->and($numbers[0]->isDefault)->toBeTrue()
        ->and($numbers[0]->isActive)->toBeTrue()
        ->and($numbers[0]->label)->toBe('Main number');

    $this->inTenant($this->tenant, function () use ($numbers): void {
        expect(($this->directory)()->default(MarketingChannel::Whatsapp)?->id)->toBe($numbers[0]->id)
            ->and(($this->directory)()->find($numbers[0]->id)?->accountId)->toBe('109876543210')
            ->and(AccountClaim::query()->where('number_id', $numbers[0]->id)->where('account_value', '109876543210')->exists())->toBeTrue();
    });
});

it('registers several numbers, each claimed platform-wide, and lists the default first', function () {
    $second = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line', '+880 1700 000002'));
    $third = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '309876543210', 'Sales line'));

    expect($second->ok)->toBeTrue()
        ->and($second->number->isDefault)->toBeFalse()
        ->and($second->number->displayNumber)->toBe('+880 1700 000002')
        ->and($third->ok)->toBeTrue();

    $labels = $this->inTenant($this->tenant, fn () => array_map(fn ($n) => $n->label, ($this->directory)()->numbers(MarketingChannel::Whatsapp)));
    expect($labels)->toBe(['Main number', 'Sales line', 'Support line']);

    $this->inTenant($this->tenant, function () use ($second): void {
        expect(AccountClaim::query()->where('number_id', $second->number->id)->value('account_value'))->toBe('209876543210');
    });
});

it('refuses a number another workspace already holds, whichever way it is connected', function () {
    $other = $this->waTenant('numbers-other');
    $this->waConnectCloud($other, '777000111222', '555000222');

    // The connection form claims the number when it is saved; a connection written straight to the table is
    // reconciled (and claimed) the first time its numbers are read.
    $this->inTenant($this->tenant, fn () => ($this->directory)()->numbers(MarketingChannel::Whatsapp));

    $viaRegistry = $this->inTenant($other, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '109876543210', 'Stolen'));
    expect($viaRegistry->ok)->toBeFalse()->and($viaRegistry->reason)->toBe('account_in_use');

    // The first workspace's secondary number is protected as well.
    $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'));
    $again = $this->inTenant($other, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Also support'));
    expect($again->reason)->toBe('account_in_use');

    // The connection form is refused for the same reason.
    $this->inTenant($other, function (): void {
        expect(fn () => app(ChannelConnections::class)->save(Channel::Whatsapp, [
            'provider' => 'whatsapp_cloud',
            'settings' => ['phone_number_id' => '209876543210'],
            'credential_ref' => 'wa_token',
            'webhook_secret_ref' => 'wa_app',
        ]))->toThrow(ValidationException::class);
    });

    // And the second workspace still sees only its own number.
    $this->inTenant($other, function (): void {
        $numbers = ($this->directory)()->numbers(MarketingChannel::Whatsapp);
        expect($numbers)->toHaveCount(1)->and($numbers[0]->accountId)->toBe('777000111222');
    });
});

it('validates what it registers and keeps labels unique', function () {
    $register = fn (string $account, string $label, ?string $display = null) => $this->inTenant(
        $this->tenant,
        fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, $account, $label, $display),
    );

    expect($register('abc', 'X')->reason)->toBe('invalid_account_id')
        ->and($register('12', 'X')->reason)->toBe('invalid_account_id')
        ->and($register('209876543210', ' ')->reason)->toBe('invalid_label')
        ->and($register('209876543210', str_repeat('l', 61))->reason)->toBe('invalid_label')
        ->and($register('209876543210', 'X', '<script>')->reason)->toBe('invalid_label')
        ->and($register('209876543210', 'Main number')->reason)->toBe('label_in_use')
        ->and($register('109876543210', 'Dup')->reason)->toBe('account_in_use')
        ->and($register('209876543210', 'Fine')->ok)->toBeTrue();

    $unsupported = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Sms, '209876543211', 'Sms'));
    expect($unsupported->reason)->toBe('unsupported_channel');
});

it('says not_connected without a connection and module_unavailable without entitlement', function () {
    $bare = $this->waTenant('numbers-bare');
    $result = $this->inTenant($bare, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '409876543210', 'Line'));
    expect($result->reason)->toBe('not_connected')
        ->and($this->inTenant($bare, fn () => ($this->directory)()->numbers(MarketingChannel::Whatsapp)))->toBe([]);

    $plain = $this->createTenant('numbers-plain');
    $result = $this->inTenant($plain, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '409876543211', 'Line'));
    expect($result->reason)->toBe('module_unavailable')
        ->and($this->inTenant($plain, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp)))->toBeNull();
});

it('keeps the legacy phone number id setting equal to the default number', function () {
    $second = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line', null, makeDefault: true));
    expect($second->number->isDefault)->toBeTrue();

    $this->inTenant($this->tenant, function (): void {
        $connection = ChannelConnection::query()->where('channel', 'whatsapp')->firstOrFail();
        expect($connection->settings['phone_number_id'])->toBe('209876543210')
            ->and($connection->settings['business_account_id'])->toBe('555000111');

        $main = array_values(array_filter(($this->directory)()->numbers(MarketingChannel::Whatsapp), fn ($n) => $n->label === 'Main number'))[0];
        expect($main->isDefault)->toBeFalse();

        $back = ($this->registry)()->makeDefault($main->id);
        expect($back->ok)->toBeTrue()
            ->and(ChannelConnection::query()->where('channel', 'whatsapp')->value('settings')['phone_number_id'])->toBe('109876543210')
            ->and(ChannelPhoneNumber::query()->where('is_default', true)->count())->toBe(1);
    });
});

it('follows a changed connection setting: replace for one number, add and promote for several', function () {
    $this->inTenant($this->tenant, function (): void {
        $connection = ChannelConnection::query()->where('channel', 'whatsapp')->firstOrFail();
        ($this->directory)()->numbers(MarketingChannel::Whatsapp);

        // One number: the legacy "change the number" replaces it.
        $connection->forceFill(['settings' => [...$connection->settings, 'phone_number_id' => '119876543210']])->save();
        $numbers = ($this->directory)()->numbers(MarketingChannel::Whatsapp);
        expect($numbers)->toHaveCount(1)->and($numbers[0]->accountId)->toBe('119876543210');

        // Two numbers: a new setting adds a number and makes it the default.
        ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line');
        $connection->refresh()->forceFill(['settings' => [...$connection->settings, 'phone_number_id' => '129876543210']])->save();
        $numbers = ($this->directory)()->numbers(MarketingChannel::Whatsapp);
        expect($numbers)->toHaveCount(3)->and($numbers[0]->accountId)->toBe('129876543210')->and($numbers[0]->isDefault)->toBeTrue();
    });
});

it('updates, disables and removes numbers under clear rules', function () {
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
    $main = $this->inTenant($this->tenant, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp));

    $this->inTenant($this->tenant, function () use ($support, $main): void {
        $registry = ($this->registry)();

        expect($registry->update($support->id, label: 'Help desk', displayNumber: '+8801700000002')->number->label)->toBe('Help desk')
            ->and($registry->update($support->id, label: 'Main number')->reason)->toBe('label_in_use')
            ->and($registry->update('01HZ0000000000000000000099', label: 'X')->reason)->toBe('not_found')
            ->and($registry->update($main->id, active: false)->reason)->toBe('is_default')
            ->and($registry->update($support->id, active: false)->number->isActive)->toBeFalse()
            ->and($registry->makeDefault($support->id)->reason)->toBe('inactive')
            ->and($registry->remove($main->id)->reason)->toBe('is_default');

        $removed = $registry->remove($support->id);
        expect($removed->ok)->toBeTrue()
            ->and(ChannelPhoneNumber::query()->count())->toBe(1)
            ->and(AccountClaim::query()->where('account_value', '209876543210')->exists())->toBeFalse();
    });
});

it('does not remove a number once messages were sent from it, and disables it instead', function () {
    $contact = $this->waContact($this->tenant);
    $this->waOptIn($this->tenant, $contact);
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;

    $receipt = $this->inTenant($this->tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'k1', $support->id)));
    expect($receipt->state)->toBe(DeliveryState::Queued)->and($receipt->senderNumberId)->toBe($support->id);

    $this->inTenant($this->tenant, function () use ($support): void {
        expect(($this->registry)()->remove($support->id)->reason)->toBe('in_use')
            ->and(($this->registry)()->update($support->id, active: false)->ok)->toBeTrue();
    });
});

it('sends from the default number when none is named and from the named number otherwise', function () {
    $contact = $this->waContact($this->tenant);
    $this->waOptIn($this->tenant, $contact);
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
    $main = $this->inTenant($this->tenant, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp));

    $viaDefault = $this->inTenant($this->tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'default')));
    $viaSupport = $this->inTenant($this->tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'support', $support->id)));

    expect($viaDefault->state)->toBe(DeliveryState::Queued)->and($viaDefault->senderNumberId)->toBe($main->id)
        ->and($viaSupport->state)->toBe(DeliveryState::Queued)->and($viaSupport->senderNumberId)->toBe($support->id);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/109876543210/messages') && $request['text']['body'] === 'Hello');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/209876543210/messages'));

    $this->inTenant($this->tenant, function () use ($main, $support): void {
        expect(Message::query()->where('idempotency_key', 'whatsapp_marketing:default')->value('sender_number_id'))->toBe($main->id)
            ->and(Message::query()->where('idempotency_key', 'whatsapp_marketing:support')->value('sender_number_id'))->toBe($support->id);

        // A replayed key answers with the original message's number and sends nothing new.
        $replay = numbersGateway()->send(numbersMessage(Message::query()->first()->contact_id, 'support', $main->id));
        expect($replay->replayed)->toBeTrue()->and($replay->senderNumberId)->toBe($support->id);
    });
});

it('answers sender_number_unavailable for an unknown, foreign, disabled or removed number and records nothing', function () {
    $contact = $this->waContact($this->tenant);
    $this->waOptIn($this->tenant, $contact);

    $other = $this->waTenant('numbers-foreign');
    $this->waConnectCloud($other, '888000111222');
    $foreign = $this->inTenant($other, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp));

    $disabled = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Old line'))->number;
    $this->inTenant($this->tenant, fn () => ($this->registry)()->update($disabled->id, active: false));

    foreach (['01HZ0000000000000000000099', $foreign->id, $disabled->id] as $index => $numberId) {
        $receipt = $this->inTenant($this->tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, "bad{$index}", $numberId)));

        expect($receipt->state)->toBe(DeliveryState::Unavailable)->and($receipt->reason)->toBe('sender_number_unavailable')->and($receipt->isRecorded())->toBeFalse();
    }

    expect($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(0);
    Http::assertNothingSent();
});

it('fails a queued message whose number was disabled before the worker ran', function () {
    $contact = $this->waContact($this->tenant);
    $this->waOptIn($this->tenant, $contact);
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;

    $this->inTenant($this->tenant, function () use ($contact, $support): void {
        $receipt = numbersGateway()->send(new MarketingMessage(
            module: 'whatsapp_marketing',
            idempotencyKey: 'later',
            channel: MarketingChannel::Whatsapp,
            contactId: $contact->id,
            subject: null,
            body: 'Later',
            sendAt: now()->addHour(),
            senderNumberId: $support->id,
        ));
        expect($receipt->state)->toBe(DeliveryState::Queued);

        ($this->registry)()->update($support->id, active: false);
        Message::query()->whereKey($receipt->messageId)->update(['next_attempt_at' => now()->subMinute()]);
        app(MessageDispatcher::class)->deliver($receipt->messageId);

        $message = Message::query()->findOrFail($receipt->messageId);
        expect($message->status->value)->toBe('failed')->and($message->failure_code)->toBe('sender_number_unavailable');
    });

    Http::assertNothingSent();
});

it('accepts webhook events for any of the workspace numbers and tags the event with the number', function () {
    $contact = $this->waContact($this->tenant);
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
    $main = $this->inTenant($this->tenant, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp));

    $this->waInbound($this->tenant, '+8801711000001', 'to main', 'wamid.IN-MAIN', '109876543210')->assertOk();
    $this->waInbound($this->tenant, '+8801711000001', 'to support', 'wamid.IN-SUPPORT', '209876543210')->assertOk();

    expect(NumbersEventCollector::$events)->toHaveCount(2)
        ->and(NumbersEventCollector::$events[0]->numberId)->toBe($main->id)
        ->and(NumbersEventCollector::$events[0]->details['phone_number_id'])->toBe('109876543210')
        ->and(NumbersEventCollector::$events[1]->numberId)->toBe($support->id)
        ->and(NumbersEventCollector::$events[1]->text)->toBe('to support')
        ->and(NumbersEventCollector::$events[1]->type)->toBe(ChannelEventType::Inbound)
        ->and($contact->id)->not->toBe('');
});

it('drops events for numbers the workspace does not hold, even when another workspace does', function () {
    $other = $this->waTenant('numbers-peer');
    $this->waConnectCloud($other, '999000111222');

    // The other workspace's number, delivered to THIS workspace's webhook: dropped.
    $this->waInbound($this->tenant, '+8801711000001', 'misrouted', 'wamid.IN-X', '999000111222')->assertOk();
    $this->waInbound($this->tenant, '+8801711000001', 'unknown', 'wamid.IN-Y', '555555555555')->assertOk();

    expect(NumbersEventCollector::$events)->toBe([]);
});

it('attributes delivery receipts to the number the message left from', function () {
    $contact = $this->waContact($this->tenant);
    $this->waOptIn($this->tenant, $contact);
    $support = $this->inTenant($this->tenant, fn () => ($this->registry)()->register(MarketingChannel::Whatsapp, '209876543210', 'Support line'))->number;
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT-S']]], 200)]);

    $this->inTenant($this->tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'rcpt', $support->id)));
    $this->waStatus($this->tenant, 'wamid.OUT-S', 'delivered', ['pricing' => ['billable' => true, 'category' => 'service']], '209876543210')->assertOk();

    expect(NumbersEventCollector::$events)->toHaveCount(1)
        ->and(NumbersEventCollector::$events[0]->type)->toBe(ChannelEventType::Delivered)
        ->and(NumbersEventCollector::$events[0]->numberId)->toBe($support->id)
        ->and(NumbersEventCollector::$events[0]->details['pricing_category'])->toBe('service');
});

it('isolates numbers between workspaces', function () {
    $other = $this->waTenant('numbers-iso');
    $this->waConnectCloud($other, '666000111222');
    $mine = $this->inTenant($this->tenant, fn () => ($this->directory)()->default(MarketingChannel::Whatsapp));

    $this->inTenant($other, function () use ($mine): void {
        expect(($this->directory)()->find($mine->id))->toBeNull()
            ->and(($this->registry)()->update($mine->id, label: 'Hijack')->reason)->toBe('not_found')
            ->and(($this->registry)()->makeDefault($mine->id)->reason)->toBe('not_found')
            ->and(($this->registry)()->remove($mine->id)->reason)->toBe('not_found');
    });

    $this->assertTenantIsolated(ChannelPhoneNumber::class, function (): ChannelPhoneNumber {
        $connection = ChannelConnection::query()->create(['channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true]);

        return ChannelPhoneNumber::query()->create([
            'connection_id' => $connection->id,
            'channel' => 'whatsapp',
            'phone_number_id' => (string) random_int(10000000000, 99999999999),
            'label' => 'Line',
            'is_active' => true,
        ]);
    });
});

it('works unchanged for a provider without per-number accounts', function () {
    $tenant = $this->waTenant('numbers-fake');
    $this->waConnectFake($tenant);
    $contact = $this->waContact($tenant);
    $this->waOptIn($tenant, $contact);

    expect($this->inTenant($tenant, fn () => ($this->directory)()->numbers(MarketingChannel::Whatsapp)))->toBe([]);

    $receipt = $this->inTenant($tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'fake-1')));
    expect($receipt->state)->toBe(DeliveryState::Queued)->and($receipt->senderNumberId)->toBeNull();

    $named = $this->inTenant($tenant, fn () => numbersGateway()->send(numbersMessage($contact->id, 'fake-2', '01HZ0000000000000000000099')));
    expect($named->reason)->toBe('sender_number_unavailable');
});

it('has honest null implementations when the communication app is absent', function () {
    $null = new NullChannelNumbers;

    expect($null->numbers(MarketingChannel::Whatsapp))->toBe([])
        ->and($null->default(MarketingChannel::Whatsapp))->toBeNull()
        ->and($null->find('x'))->toBeNull()
        ->and($null->register(MarketingChannel::Whatsapp, '123456', 'L')->reason)->toBe('module_unavailable')
        ->and($null->remove('x')->ok)->toBeFalse();
});

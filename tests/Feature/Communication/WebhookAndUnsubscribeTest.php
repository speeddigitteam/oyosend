<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\OutboundRequest;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Actions\UnsubscribeLinks;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\Suppression;
use App\Modules\Communication\Models\UnsubscribeToken;
use App\Modules\Communication\Models\WebhookEvent;
use App\Modules\Communication\Testing\InMemoryProvider;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-hook');
    $this->connectFakeProvider($this->tenant);
    $this->contact = $this->commContact($this->tenant, ['phone' => '+8801711223344']);
    $this->message = $this->inTenant($this->tenant, fn () => Message::query()->create([
        'channel' => Channel::Sms, 'purpose' => MessagePurpose::Transactional, 'status' => MessageStatus::Sent,
        'recipient' => '+8801711223344', 'body' => 'Hi', 'locale' => 'en', 'idempotency_key' => 'm-1',
        'provider' => 'fake_sms', 'provider_message_id' => 'prov-1', 'contact_id' => $this->contact->id, 'attempts' => 1,
    ]));
});

it('rejects an unsigned, wrongly signed or tampered webhook and changes nothing', function () {
    $payload = ['events' => [['id' => 'e1', 'type' => 'delivered', 'message_id' => 'prov-1']]];

    $this->webhook($this->tenant, 'fake_sms', $payload, secret: null)->assertUnauthorized();
    $this->webhook($this->tenant, 'fake_sms', $payload, secret: 'wrong-secret')->assertUnauthorized();
    $this->webhook($this->tenant, 'fake_sms', $payload, signature: 'abc123')->assertUnauthorized();

    $this->inTenant($this->tenant, function (): void {
        expect($this->message->fresh()->status)->toBe(MessageStatus::Sent)
            ->and(WebhookEvent::query()->count())->toBe(0);
    });
});

it('answers 404 for a provider the workspace has not connected, or one without a webhook secret', function () {
    $this->webhook($this->tenant, 'no_such_provider', ['events' => []])->assertNotFound();
    $this->webhook($this->tenant, 'fake_whatsapp', ['events' => []])->assertNotFound();

    $this->inTenant($this->tenant, fn () => ChannelConnection::query()->update(['webhook_secret_ref' => null]));
    $this->webhook($this->tenant, 'fake_sms', ['events' => []])->assertNotFound();
});

it('marks a message delivered once, however often the provider repeats the event', function () {
    $payload = ['events' => [['id' => 'e1', 'type' => 'delivered', 'message_id' => 'prov-1']]];

    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();
    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $message = $this->message->fresh();
        expect($message->status)->toBe(MessageStatus::Delivered)
            ->and($message->delivered_at)->not->toBeNull()
            ->and(WebhookEvent::query()->count())->toBe(1);
    });
});

it('suppresses an address that bounced and records the status', function () {
    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'b1', 'type' => 'bounced', 'message_id' => 'prov-1']]])->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect($this->message->fresh()->status)->toBe(MessageStatus::Bounced)
            ->and(Suppression::query()->where('address', '+8801711223344')->where('scope', 'all')->where('reason', 'bounced')->exists())->toBeTrue();
    });
});

it('opts a contact out and suppresses the address after a complaint', function () {
    $this->optIn($this->tenant, $this->contact, Channel::Sms);

    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'c1', 'type' => 'complained', 'message_id' => 'prov-1']]])->assertOk();

    $this->inTenant($this->tenant, function (): void {
        expect(Consent::query()->where('contact_id', $this->contact->id)->value('status'))->toBe(ConsentStatus::OptedOut)
            ->and(Suppression::query()->where('reason', 'complained')->exists())->toBeTrue();
    });
});

it('puts an inbound message on the matching contact timeline', function () {
    $payload = ['events' => [['id' => 'in1', 'type' => 'inbound', 'address' => '+880 1711-223344', 'text' => 'Please call me back']]];

    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();
    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $entry = Interaction::query()->where('contact_id', $this->contact->id)->sole();
        expect($entry->type->value)->toBe('sms')
            ->and($entry->direction)->toBe('inbound')
            ->and($entry->summary)->toBe('Please call me back');
    });
});

it('ignores a verified event for a message it does not know', function () {
    $this->webhook($this->tenant, 'fake_sms', ['events' => [['id' => 'x1', 'type' => 'delivered', 'message_id' => 'nope']]])->assertOk();

    $this->inTenant($this->tenant, fn () => expect($this->message->fresh()->status)->toBe(MessageStatus::Sent));
});

it('never lets a webhook secret of one workspace verify another workspace', function () {
    $other = $this->commTenant('comm-hook-b');
    $this->inTenant($other, fn () => ChannelConnection::query()->create([
        'channel' => Channel::Sms, 'provider' => 'fake_sms', 'webhook_secret_ref' => 'fake_webhook',
    ]));
    config(['communication.credentials.fake_webhook' => ['secret' => 'whsec-test', 'tenants' => ['comm-hook']]]);

    $payload = ['events' => [['id' => 'e1', 'type' => 'delivered', 'message_id' => 'prov-1']]];

    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();
    // The reference is reserved for the first workspace, so the second has no usable secret.
    $this->webhook($other, 'fake_sms', $payload)->assertNotFound();
});

it('stores only a hash of the unsubscribe token and opts out on POST, not on GET', function () {
    $this->inTenant($this->tenant, fn () => Message::query()->whereKey($this->message->id)->update(['purpose' => 'marketing']));
    $this->optIn($this->tenant, $this->contact, Channel::Sms);

    $url = $this->inTenant($this->tenant, fn () => app(UnsubscribeLinks::class)->issue($this->message->fresh()));
    $token = basename($url);

    $this->inTenant($this->tenant, function () use ($token): void {
        $row = UnsubscribeToken::query()->sole();
        expect($row->token_hash)->toBe(hash('sha256', $token))
            ->and(json_encode(UnsubscribeToken::query()->sole()->getAttributes()))->not->toContain($token);
    });

    // Opening the link (a scanner, a preview) changes nothing.
    $this->get($this->commUrl($this->tenant, '/unsubscribe/'.$token))->assertOk()->assertSee('Unsubscribe');
    $this->inTenant($this->tenant, fn () => expect(Consent::query()->value('status'))->toBe(ConsentStatus::OptedIn));

    $this->post($this->commUrl($this->tenant, '/unsubscribe/'.$token))->assertOk()->assertSee('unsubscribed');

    $this->inTenant($this->tenant, function (): void {
        expect(Consent::query()->value('status'))->toBe(ConsentStatus::OptedOut)
            ->and(Suppression::query()->where('scope', 'marketing')->where('reason', 'unsubscribed')->exists())->toBeTrue()
            ->and(UnsubscribeToken::query()->sole()->used_at)->not->toBeNull();
    });

    // Again is harmless.
    $this->post($this->commUrl($this->tenant, '/unsubscribe/'.$token))->assertOk();
});

it('answers the same for an unknown, malformed or other-workspace token', function () {
    $this->get($this->commUrl($this->tenant, '/unsubscribe/'.str_repeat('a', 40)))->assertOk()->assertSee('not valid');
    $this->post($this->commUrl($this->tenant, '/unsubscribe/short'))->assertOk()->assertSee('not valid');

    $url = $this->inTenant($this->tenant, fn () => app(UnsubscribeLinks::class)->issue($this->message));
    $other = $this->commTenant('comm-hook-c');

    $this->post($this->commUrl($other, '/unsubscribe/'.basename($url)))->assertOk()->assertSee('not valid');
    $this->inTenant($this->tenant, fn () => expect(UnsubscribeToken::query()->sole()->used_at)->toBeNull());
});

it('puts an unsubscribe link and headers into a marketing message the provider receives', function () {
    $this->optIn($this->tenant, $this->contact, Channel::Sms);
    $message = $this->inTenant($this->tenant, fn () => app(Outbox::class)->queue(
        new OutboundRequest(
            Channel::Sms, MessagePurpose::Marketing, '+8801711223344', null, 'Big sale', 'promo-1', contactId: $this->contact->id,
        ),
    ));

    expect($this->inTenant($this->tenant, fn () => $message->fresh()->status))->toBe(MessageStatus::Sent)
        ->and(InMemoryProvider::$sent)->toHaveCount(1)
        ->and(InMemoryProvider::$sent[0]->unsubscribeUrl)->toContain('/communication/unsubscribe/');
});

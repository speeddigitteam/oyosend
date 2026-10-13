<?php

declare(strict_types=1);

use App\Core\Billing\UsageMeter;
use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Support\PhoneNumber;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Actions\MessageDispatcher;
use App\Modules\Communication\Actions\OutboundRequest;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\AccountClaim;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\Suppression;
use App\Modules\Communication\Models\WebhookEvent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

/** A listener that fails on demand, to prove an inbound message is never silently lost. */
final class FailingInboundListener implements ChannelEventListener
{
    public static bool $fail = true;

    public function handle(ChannelEvent $event): void
    {
        if (self::$fail) {
            throw new RuntimeException('boom with customer text');
        }
    }
}

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-fix');
    $this->queue = fn (array $overrides = []) => $this->inTenant($this->tenant, fn () => app(Outbox::class)->queue(new OutboundRequest(...[
        'channel' => Channel::Email,
        'purpose' => MessagePurpose::Transactional,
        'recipient' => 'customer@example.test',
        'subject' => 'Your order',
        'body' => 'It is on its way.',
        'idempotencyKey' => 'order-1',
        ...$overrides,
    ])));
    $this->fresh = fn (Message $message) => $this->inTenant($this->tenant, fn () => $message->fresh());
});

// --- composer purpose ----------------------------------------------------------------------------------------

it('sends free text as marketing and refuses a transactional request without the sensitive permission', function () {
    Mail::fake();
    [$user] = $this->commMember($this->tenant, ['core.contacts.view', 'communication.messages.send']);
    $contact = $this->commContact($this->tenant, ['email' => 'free@example.test']);
    $this->actingAsMember($this->tenant, $user);

    // Explicit transactional: refused, nothing queued.
    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Hi', 'body' => 'Buy now',
    ])->assertSessionHasErrors('purpose');
    expect($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(0);

    // No purpose at all: it is marketing, so without a recorded opt-in it does not go out.
    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'subject' => 'Hi', 'body' => 'Buy now',
    ])->assertSessionHasErrors('contact_id');
    Mail::assertNothingSent();

    // With the opt-in it is sent, and stored as marketing.
    $this->optIn($this->tenant, $contact);
    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'subject' => 'Hi', 'body' => 'Buy now',
    ])->assertRedirect();

    $stored = $this->inTenant($this->tenant, fn () => Message::query()->firstOrFail());
    expect($stored->purpose)->toBe(MessagePurpose::Marketing);
});

it('lets a member with the sensitive permission send free text as transactional', function () {
    Mail::fake();
    [$user] = $this->commMember($this->tenant, ['core.contacts.view', 'communication.messages.send', 'communication.messages.send_transactional']);
    $contact = $this->commContact($this->tenant, ['email' => 'svc@example.test']);
    $this->actingAsMember($this->tenant, $user);

    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Receipt', 'body' => 'Thanks',
    ])->assertRedirect();

    $stored = $this->inTenant($this->tenant, fn () => Message::query()->firstOrFail());
    expect($stored->purpose)->toBe(MessagePurpose::Transactional);
});

it('takes a template purpose from the template, never from the request', function () {
    Mail::fake();
    [$user] = $this->commMember($this->tenant, ['core.contacts.view', 'communication.messages.send']);
    $contact = $this->commContact($this->tenant, ['email' => 'tpl@example.test']);
    $template = $this->inTenant($this->tenant, fn () => MessageTemplate::query()->create([
        'slug' => 'promo', 'name' => 'Promo', 'channel' => 'email', 'locale' => 'en', 'purpose' => 'marketing',
        'subject' => 'Sale', 'body' => 'Sale now', 'is_active' => true,
    ]));
    $this->actingAsMember($this->tenant, $user);

    // The sender claims transactional; the marketing template stays marketing and needs consent.
    $this->post($this->commUrl($this->tenant, '/messages'), [
        'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'template_id' => $template->id,
    ])->assertSessionHasErrors('contact_id');
    Mail::assertNothingSent();
});

it('does not return another message for a reused request id with different content', function () {
    Mail::fake();
    $this->actingAsOwner($this->tenant);
    $contact = $this->commContact($this->tenant, ['email' => 'idem@example.test']);
    $url = $this->commUrl($this->tenant, '/messages');
    $base = ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Hi', 'request_id' => 'req-1'];

    $this->post($url, [...$base, 'body' => 'First'])->assertRedirect();
    $this->post($url, [...$base, 'body' => 'First'])->assertRedirect();
    $this->post($url, [...$base, 'body' => 'Second'])->assertRedirect();

    $bodies = $this->inTenant($this->tenant, fn () => Message::query()->orderBy('created_at')->pluck('body')->all());
    expect($bodies)->toHaveCount(2)->and($bodies)->toContain('First', 'Second');
});

// --- plan limit ----------------------------------------------------------------------------------------------

it('counts queued messages against the plan limit so a backlog cannot be queued over it', function () {
    Queue::fake();
    $plan = $this->createFreePlan(['limits' => ['communication.messages' => 2]]);
    $this->subscribe($this->tenant, $plan);

    $first = ($this->queue)(['idempotencyKey' => 'b-1']);
    $second = ($this->queue)(['idempotencyKey' => 'b-2']);
    $third = ($this->queue)(['idempotencyKey' => 'b-3']);

    expect($first->status)->toBe(MessageStatus::Queued)
        ->and($second->status)->toBe(MessageStatus::Queued)
        ->and($third->status)->toBe(MessageStatus::Failed)
        ->and($third->failure_code)->toBe('limit_reached');
});

it('checks the plan limit again at worker time', function () {
    Queue::fake();
    Mail::fake();
    $plan = $this->createFreePlan(['limits' => ['communication.messages' => 1]]);
    $this->subscribe($this->tenant, $plan);

    $queued = ($this->queue)(['idempotencyKey' => 'w-1']);
    expect($queued->status)->toBe(MessageStatus::Queued);

    // Something else used the allowance in between (another channel's send, an earlier backlog).
    $this->inTenant($this->tenant, fn () => app(UsageMeter::class)->record(Outbox::LIMIT, 1, 'elsewhere', [], now()));
    $this->inTenant($this->tenant, fn () => app(MessageDispatcher::class)->deliver($queued->id));

    $stored = ($this->fresh)($queued);
    expect($stored->status)->toBe(MessageStatus::Failed)->and($stored->failure_code)->toBe('limit_reached');
    Mail::assertNothingSent();
});

// --- phone normalization --------------------------------------------------------------------------------------

it('reads every Bangladesh spelling as one canonical number and guesses nothing else', function () {
    foreach (['01712345678', '1712345678', '8801712345678', '+8801712345678', '008801712345678', '+880 1712-345678', '০১৭১২৩৪৫৬৭৮'] as $spelling) {
        expect(PhoneNumber::normalize($spelling))->toBe('+8801712345678')
            ->and(Channel::Sms->normalize($spelling))->toBe('+8801712345678')
            ->and(Channel::Whatsapp->normalize($spelling))->toBe('+8801712345678');
    }

    expect(PhoneNumber::normalize('+14155550100'))->toBe('+14155550100')
        ->and(PhoneNumber::normalize('0014155550100'))->toBe('+14155550100')
        ->and(PhoneNumber::normalize('4155550100'))->toBeNull()
        ->and(PhoneNumber::normalize('0171234'))->toBeNull()
        ->and(PhoneNumber::normalize('+880 2 9999999'))->toBeNull()
        ->and(PhoneNumber::normalize(''))->toBeNull();
});

it('suppresses a number once however it was written', function () {
    $this->inTenant($this->tenant, function (): void {
        $suppressions = app(SuppressionManager::class);
        $suppressions->suppress(Channel::Sms, '01712345678', SuppressionScope::All, SuppressionReason::Manual);
        $suppressions->suppress(Channel::Sms, '8801712345678', SuppressionScope::All, SuppressionReason::Manual);
        $suppressions->suppress(Channel::Sms, '+8801712345678', SuppressionScope::All, SuppressionReason::Manual);

        expect(Suppression::query()->count())->toBe(1)
            ->and(Suppression::query()->value('address'))->toBe('+8801712345678');

        foreach (['01712345678', '8801712345678', '+8801712345678'] as $spelling) {
            expect($suppressions->blocks(Channel::Sms, Channel::Sms->normalize($spelling), MessagePurpose::Transactional))->toBeTrue();
        }
    });
});

it('sends through the gateway to the canonical number of a contact saved in national form', function () {
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    $contact = $this->commContact($this->tenant, ['phone' => '01712345678']);
    $this->optIn($this->tenant, $contact, Channel::Sms);
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(
        Channel::Sms, '+8801712345678', SuppressionScope::Marketing, SuppressionReason::Unsubscribed, $contact->id,
    ));

    $receipt = $this->inTenant($this->tenant, fn () => app(ChannelGateway::class)->send(new MarketingMessage(
        module: 'sms_marketing',
        idempotencyKey: 'k-1',
        channel: MarketingChannel::Sms,
        contactId: $contact->id,
        subject: null,
        body: 'Hello',
    )));

    // The national spelling is the same address as the suppressed canonical one.
    expect($receipt->state->value)->toBe('blocked')
        ->and($this->inTenant($this->tenant, fn () => Message::query()->firstOrFail()->recipient))->toBe('+8801712345678');
});

// --- webhook atomicity ---------------------------------------------------------------------------------------

it('keeps nothing of an inbound event whose listener failed, so the provider redelivery is applied', function () {
    $this->connectFakeProvider($this->tenant, Channel::Sms);
    $this->commContact($this->tenant, ['phone' => '+8801711223344']);
    $this->app->bind(FailingInboundListener::class, fn () => new FailingInboundListener);
    $this->app->tag([FailingInboundListener::class], ChannelEventListener::TAG);
    $payload = ['events' => [['id' => 'in-fail', 'type' => 'inbound', 'address' => '01711223344', 'text' => 'Please call me back']]];

    FailingInboundListener::$fail = true;
    $this->webhook($this->tenant, 'fake_sms', $payload)->assertStatus(503);
    $this->inTenant($this->tenant, function (): void {
        expect(WebhookEvent::query()->count())->toBe(0)
            ->and(Interaction::query()->count())->toBe(0);
    });

    // The provider retries: this time it is applied once.
    FailingInboundListener::$fail = false;
    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();
    $this->webhook($this->tenant, 'fake_sms', $payload)->assertOk();
    $this->inTenant($this->tenant, function (): void {
        expect(WebhookEvent::query()->count())->toBe(1)
            ->and(Interaction::query()->count())->toBe(1);
    });
});

// --- consent recorder ----------------------------------------------------------------------------------------

it('records an opt-out from a keyword as consent withdrawn and the number suppressed for marketing', function () {
    $contact = $this->commContact($this->tenant, ['phone' => '01712345678']);
    $this->optIn($this->tenant, $contact, Channel::Whatsapp);

    $recorded = $this->inTenant($this->tenant, fn () => app(ConsentRecorder::class)
        ->recordOptOut($contact->id, MarketingChannel::Whatsapp, 'keyword', 'WhatsApp keyword'));

    expect($recorded)->toBeTrue();
    $this->inTenant($this->tenant, function () use ($contact): void {
        expect(Consent::query()->where('contact_id', $contact->id)->where('channel', 'whatsapp')->value('status')->value)->toBe('opted_out')
            ->and(app(Outbox::class)->blockedReason(Channel::Whatsapp, MessagePurpose::Marketing, '+8801712345678', $contact->id))->toBe('suppressed')
            ->and(app(ConsentManager::class)->hasOptedIn($contact->id, Channel::Whatsapp))->toBeFalse();
    });

    expect(fn () => $this->inTenant($this->tenant, fn () => app(ConsentRecorder::class)
        ->recordOptOut($contact->id, MarketingChannel::Whatsapp, 'made_up')))->toThrow(InvalidArgumentException::class);
});

// --- connection account claims -------------------------------------------------------------------------------

it('lets one workspace connect a provider account and refuses a second workspace', function () {
    config(['communication.credentials.wa_shared' => ['secret' => 'token', 'tenants' => null]]);
    $other = $this->commTenant('comm-fix-b');
    config(['communication.credentials.wa_shared' => ['secret' => 'token', 'tenants' => null]]);
    $input = [
        'provider' => 'whatsapp_cloud', 'is_enabled' => true, 'settings' => ['phone_number_id' => '109876543210'],
        'credential_ref' => 'wa_shared', 'webhook_secret_ref' => null, 'rate_limit_per_minute' => null,
    ];

    $this->actingAsOwner($this->tenant)->put($this->commUrl($this->tenant, '/settings/channels/whatsapp'), $input)->assertRedirect();
    // Saving again is not a conflict with itself.
    $this->actingAsOwner($this->tenant)->put($this->commUrl($this->tenant, '/settings/channels/whatsapp'), $input)->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAsOwner($other)->put($this->commUrl($other, '/settings/channels/whatsapp'), $input)
        ->assertSessionHasErrors('settings.phone_number_id');

    $owner = $this->inTenant($this->tenant, fn () => ChannelConnection::query()->where('channel', 'whatsapp')->value('id'));
    expect(AccountClaim::query()->where('account_value', '109876543210')->count())->toBe(1)
        ->and(AccountClaim::query()->where('account_value', '109876543210')->value('connection_id'))->toBe($owner);
});

// --- unsubscribe route for a lapsed workspace ----------------------------------------------------------------

it('keeps the unsubscribe page answering for a workspace whose app lapsed, without disclosing anything', function () {
    $plain = $this->createTenant('comm-lapsed');

    $this->get($this->commUrl($plain, '/unsubscribe/'.str_repeat('a', 40)))->assertOk();
    // The webhooks stay gated by the entitlement.
    $this->post($this->commUrl($plain, '/webhooks/fake_sms'), [])->assertNotFound();
});

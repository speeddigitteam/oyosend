<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\InteractionType;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\AutomationActionReceipt;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\CommunicationSetting;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Interaction;
use App\Modules\Communication\Models\MemberNotification;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\NotificationPreference;
use App\Modules\Communication\Models\Suppression;
use App\Modules\Communication\Models\UnsubscribeToken;
use App\Modules\Communication\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

$membership = fn (): TenantMembership => TenantMembership::query()->firstOrFail();
$message = fn (): Message => Message::query()->create([
    'channel' => Channel::Email, 'purpose' => MessagePurpose::Transactional, 'status' => MessageStatus::Queued,
    'recipient' => 'a@example.test', 'subject' => 'Hi', 'body' => 'Body', 'locale' => 'en',
    'idempotency_key' => Str::random(20),
]);

it('isolates notifications, preferences and settings between tenants', function () use ($membership) {
    $this->assertTenantIsolated(MemberNotification::class, fn () => MemberNotification::query()->create([
        'membership_id' => $membership()->id, 'category' => 'system', 'title_key' => 'Hello',
    ]));
    $this->assertTenantIsolated(NotificationPreference::class, fn () => NotificationPreference::query()->create([
        'membership_id' => $membership()->id, 'channel' => 'email', 'category' => 'system', 'enabled' => true,
    ]));
    $this->assertTenantIsolated(CommunicationSetting::class, fn () => CommunicationSetting::query()->create([]));
    $this->assertTenantIsolated(ChannelConnection::class, fn () => ChannelConnection::query()->create([
        'channel' => Channel::Sms, 'provider' => 'none',
    ]));
});

it('isolates templates, messages and interactions between tenants', function () use ($message) {
    $this->assertTenantIsolated(MessageTemplate::class, fn () => MessageTemplate::query()->create([
        'slug' => 'welcome', 'name' => 'Welcome', 'channel' => Channel::Email, 'locale' => 'en',
        'purpose' => MessagePurpose::Transactional, 'subject' => 'Hi', 'body' => 'Hello',
    ]));
    $this->assertTenantIsolated(Message::class, fn () => $message());
    $this->assertTenantIsolated(Interaction::class, fn () => Interaction::query()->create([
        'type' => InteractionType::Note, 'summary' => 'Called', 'occurred_at' => now(),
    ]));
});

it('isolates suppressions, consent, tokens, provider events and receipts between tenants', function () {
    $this->assertTenantIsolated(Suppression::class, fn () => Suppression::query()->create([
        'channel' => Channel::Email, 'address' => 'x@example.test', 'scope' => SuppressionScope::All, 'reason' => SuppressionReason::Manual,
    ]));
    $this->assertTenantIsolated(Consent::class, fn () => Consent::query()->create([
        'contact_id' => Contact::factory()->create()->id,
        'channel' => Channel::Email, 'purpose' => MessagePurpose::Marketing, 'status' => ConsentStatus::OptedIn,
        'method' => 'form', 'recorded_at' => now(),
    ]));
    $this->assertTenantIsolated(UnsubscribeToken::class, fn () => UnsubscribeToken::query()->create([
        'channel' => Channel::Email, 'address' => 'x@example.test', 'token_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addDay(),
    ]));
    $this->assertTenantIsolated(WebhookEvent::class, fn () => WebhookEvent::query()->create([
        'provider' => 'fake_sms', 'event_id' => Str::random(12), 'type' => 'delivered', 'received_at' => now(),
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'communication.message.send', 'idempotency_key' => Str::random(10), 'output' => [],
    ]));
});

it('allows the same idempotency key, suppression and template in two workspaces but never twice in one', function () {
    $a = $this->createTenant();
    $b = $this->createTenant();
    $make = fn () => Message::query()->create([
        'channel' => Channel::Email, 'purpose' => MessagePurpose::Transactional, 'status' => MessageStatus::Queued,
        'recipient' => 'a@example.test', 'subject' => 'Hi', 'body' => 'Body', 'locale' => 'en', 'idempotency_key' => 'same-key',
    ]);

    $this->inTenant($a, fn () => $make());
    $this->inTenant($b, fn () => $make());

    expect(fn () => $this->inTenant($a, fn () => $make()))->toThrow(UniqueConstraintViolationException::class);
});

<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Enums\TemplateCategory;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Enums\WindowOrigin;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\AutomationActionReceipt;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Recipient;
use App\Modules\WhatsappMarketing\Models\Setting;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Models\Window;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

$template = fn (): Template => Template::query()->create([
    'name' => 't_'.Str::lower(Str::random(8)), 'language' => 'en_US', 'category' => TemplateCategory::Marketing,
    'status' => TemplateStatus::Approved, 'components' => [['type' => 'BODY', 'text' => 'Hi']], 'body_text' => 'Hi',
]);
$list = fn (): ContactList => ContactList::query()->create(['name' => 'List '.Str::random(6)]);
$contact = fn (): Contact => Contact::factory()->create(['phone' => '+88017'.random_int(10000000, 99999999)]);
$conversation = fn (): Conversation => Conversation::query()->create([
    'phone' => '+88018'.random_int(10000000, 99999999), 'status' => ConversationStatus::Open, 'unread_count' => 0,
]);
$campaign = fn () => Campaign::query()->create([
    'name' => 'Camp '.Str::random(6), 'status' => CampaignStatus::Draft, 'template_name' => 't', 'template_language' => 'en_US',
    'template_category' => 'marketing', 'template_body' => 'Hi', 'variable_map' => [], 'total_recipients' => 0,
]);
$message = fn (): Message => Message::query()->create([
    'conversation_id' => $conversation()->id, 'direction' => Direction::Inbound, 'sender_type' => SenderType::Customer,
    'type' => 'text', 'body' => 'Hello', 'status' => MessageStatus::Received, 'occurred_at' => now(),
]);

it('isolates the configuration records between tenants', function () use ($template, $list) {
    $this->assertTenantIsolated(Setting::class, fn () => Setting::query()->create([]));
    $this->assertTenantIsolated(Template::class, fn () => $template());
    $this->assertTenantIsolated(QuickReply::class, fn () => QuickReply::query()->create(['shortcut' => 'hi-'.Str::random(5), 'title' => 'Hi', 'body' => 'Hello']));
    $this->assertTenantIsolated(ContactList::class, fn () => $list());
    $this->assertTenantIsolated(Flow::class, fn () => Flow::query()->create([
        'name' => 'Flow '.Str::random(5), 'trigger' => FlowTrigger::Welcome, 'actions' => [['type' => 'handoff']],
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'whatsapp_marketing.template.send', 'idempotency_key' => Str::random(20), 'output' => ['state' => 'queued'],
    ]));
});

it('isolates conversations, messages and windows between tenants', function () use ($conversation, $message) {
    $this->assertTenantIsolated(Conversation::class, fn () => $conversation());
    $this->assertTenantIsolated(Message::class, fn () => $message());
    $this->assertTenantIsolated(Window::class, fn () => Window::query()->create([
        'conversation_id' => $conversation()->id, 'origin' => WindowOrigin::Customer, 'opened_at' => now(), 'expires_at' => now()->addDay(),
    ]));
});

it('isolates agents, list members, campaigns and recipients between tenants', function () use ($list, $contact, $campaign) {
    $this->assertTenantIsolated(Agent::class, function () {
        $membership = TenantMembership::query()->firstOrFail();

        return Agent::query()->create(['membership_id' => $membership->id, 'is_active' => true, 'is_available' => true]);
    });
    $this->assertTenantIsolated(ListMember::class, fn () => ListMember::query()->create(['list_id' => $list()->id, 'contact_id' => $contact()->id]));
    $this->assertTenantIsolated(Campaign::class, fn () => $campaign());
    $this->assertTenantIsolated(Recipient::class, fn () => Recipient::query()->create([
        'campaign_id' => $campaign()->id, 'contact_id' => $contact()->id, 'state' => RecipientState::Pending,
    ]));
});

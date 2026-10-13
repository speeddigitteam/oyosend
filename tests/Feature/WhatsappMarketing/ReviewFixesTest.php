<?php

declare(strict_types=1);

use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Recipient;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-review');
    $this->waConnectCloud($this->tenant);
});

it('reserves conversation windows for queued templates so a backlog cannot exceed the plan', function () {
    Queue::fake();
    $plan = $this->createFreePlan(['key' => 'one-window', 'modules' => ['whatsapp_marketing', 'communication'], 'limits' => [WindowTracker::LIMIT => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $template = $this->waTemplate($this->tenant);
    $conversations = [];

    foreach ([1, 2, 3] as $n) {
        $contact = $this->waContact($this->tenant, ['first_name' => "Reserve{$n}", 'phone' => "+880171100010{$n}"]);
        $this->waOptIn($this->tenant, $contact);
        $conversations[] = $this->waConversation($this->tenant, $contact);
    }

    $send = fn (Conversation $conversation) => $this->inTenant($this->tenant, fn () => app(Messenger::class)->sendTemplate(
        $conversation->fresh(), $template, ['Rahim', 'Acme'], null, SenderType::Campaign,
    ));

    // The first template takes the only window the plan has left (it is queued, not yet accepted).
    expect($send($conversations[0])->message->status->value)->toBe('queued');

    // The second would need another window: refused now, not queued and sent later.
    expect(fn () => $send($conversations[1]))->toThrow(ValidationException::class);
});

it('shows only the recipients and list members the viewer may see, and counts the rest', function () {
    [$user, $membership] = $this->waMember($this->tenant, [
        'whatsapp_marketing.campaigns.view', 'whatsapp_marketing.lists.view', 'core.contacts.view',
    ], ['core.contacts.view' => 'own', 'whatsapp_marketing.campaigns.view' => 'all']);
    $mine = $this->waContact($this->tenant, ['first_name' => 'Mine', 'phone' => '+8801711000201'], $membership);
    $theirs = $this->waContact($this->tenant, ['first_name' => 'Theirs', 'phone' => '+8801711000202']);
    $list = $this->waList($this->tenant, [$mine, $theirs]);
    $template = $this->waTemplate($this->tenant);

    $campaign = $this->inTenant($this->tenant, function () use ($list, $template, $mine, $theirs): Campaign {
        $campaign = Campaign::query()->create([
            'name' => 'Scoped', 'template_id' => $template->id, 'template_name' => $template->name,
            'template_language' => $template->language, 'template_category' => $template->category->value, 'template_body' => $template->body_text,
            'list_id' => $list->id, 'list_name' => $list->name, 'variable_map' => [], 'status' => 'draft',
        ]);

        foreach ([$mine, $theirs] as $contact) {
            Recipient::query()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'state' => 'pending']);
        }

        return $campaign;
    });

    $this->actingAsMember($this->tenant, $user);

    $this->get($this->waUrl($this->tenant, "/campaigns/{$campaign->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recipients.data', 1)
            ->where('recipients.data.0.name', fn (string $name): bool => str_starts_with($name, 'Mine'))
            ->where('hiddenRecipients', 1));

    $this->get($this->waUrl($this->tenant, "/lists/{$list->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('members.data', 1)
            ->where('members.data.0.contactId', $mine->id)
            ->where('hiddenMembers', 1));
});

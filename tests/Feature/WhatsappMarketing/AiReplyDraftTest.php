<?php

declare(strict_types=1);

use App\Modules\Ai\Actions\AiSettings;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithAi;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class, InteractsWithAi::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-ai-draft');
    $this->entitle($this->tenant, 'ai');
    $this->fake = $this->fakeAi();
    $this->waConnectCloud($this->tenant);
    $this->waContact($this->tenant, ['first_name' => 'Rahim']);
    $this->waInbound($this->tenant, '+8801711000001', 'Do you deliver to Sylhet?')->assertOk();
    $this->conversation = $this->inTenant($this->tenant, fn () => Conversation::query()->firstOrFail());
    $this->draft = fn ($user = null) => ($user === null ? $this->actingAsOwner($this->tenant) : $this->actingAsMember($this->tenant, $user))
        ->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'draft_reply', 'record_type' => 'whatsapp_conversation', 'record_id' => $this->conversation->id]);
});

it('drafts a reply from the conversation once the workspace allows the AI to read WhatsApp', function () {
    // New data scopes are opt-in: until enabled, the conversation is "not found" for the assistant.
    ($this->draft)()->assertNotFound();
    expect($this->fake->requests)->toBe([]);

    $this->actingAsOwner($this->tenant)->getJson($this->aiUrl($this->tenant, '/status'))->assertJsonPath('recordTypes', ['contact', 'company']);

    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['data_scopes' => ['contacts', 'companies', 'whatsapp_conversations']]));
    $this->fake->pushText('Yes, we deliver to Sylhet within three days.');

    $this->actingAsOwner($this->tenant)->getJson($this->aiUrl($this->tenant, '/status'))->assertJsonPath('recordTypes', ['contact', 'company', 'whatsapp_conversation']);

    ($this->draft)()->assertOk()->assertJsonPath('text', 'Yes, we deliver to Sylhet within three days.')->assertJsonPath('ai_generated', true);

    $content = $this->fake->requests[0]->messages[0]['content'];
    expect($content)->toContain('<record_data source="whatsapp_conversation" id="'.$this->conversation->id.'">')
        ->and($content)->toContain('Do you deliver to Sylhet?')
        ->and($content)->toContain('WhatsApp reply')
        ->and($this->fake->requests[0]->tools)->toBe([]);

    // Drafting sends nothing to the customer.
    Http::assertNothingSent();
});

it('cannot draft from a conversation the member may not see', function () {
    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['data_scopes' => ['contacts', 'whatsapp_conversations']]));
    [$noWhatsapp] = $this->aiMember($this->tenant, ['ai.drafts.generate']);
    [$ownOnly] = $this->aiMember($this->tenant, ['ai.drafts.generate', 'whatsapp_marketing.conversations.view'], ['whatsapp_marketing.conversations.view' => 'own']);

    ($this->draft)($noWhatsapp)->assertNotFound();
    ($this->draft)($ownOnly)->assertNotFound();

    expect($this->fake->requests)->toBe([]);
});

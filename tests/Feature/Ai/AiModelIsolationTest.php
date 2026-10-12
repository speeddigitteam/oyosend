<?php

declare(strict_types=1);

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Ai\Enums\MessageRole;
use App\Modules\Ai\Enums\ProposalStatus;
use App\Modules\Ai\Models\AutomationActionReceipt;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Models\Proposal;
use App\Modules\Ai\Models\Setting;
use Tests\Support\AssertsTenantIsolation;

uses(AssertsTenantIsolation::class);

function aiOwnerMembership(): TenantMembership
{
    return TenantMembership::query()->where('is_owner', true)->firstOrFail();
}

function aiMakeConversation(): Conversation
{
    $conversation = new Conversation(['title' => 'Chat', 'last_message_at' => now()]);
    $conversation->membership_id = aiOwnerMembership()->id;
    $conversation->save();

    return $conversation;
}

it('isolates AI settings per workspace', function () {
    $this->assertTenantIsolated(Setting::class, fn () => Setting::query()->create([
        'enabled' => true, 'chat_enabled' => true, 'drafting_enabled' => true, 'automation_enabled' => false,
        'data_scopes' => ['contacts'], 'retention_days' => 90, 'redact_pii' => false,
    ]));
});

it('isolates conversations per workspace', function () {
    $this->assertTenantIsolated(Conversation::class, fn () => aiMakeConversation());
});

it('isolates messages per workspace', function () {
    $this->assertTenantIsolated(Message::class, function () {
        $message = new Message(['role' => MessageRole::User, 'content' => 'hello']);
        $message->conversation_id = aiMakeConversation()->id;
        $message->save();

        return $message;
    });
});

it('isolates proposals per workspace', function () {
    $this->assertTenantIsolated(Proposal::class, fn () => Proposal::query()->create([
        'conversation_id' => aiMakeConversation()->id,
        'membership_id' => aiOwnerMembership()->id,
        'tool' => 'contacts_propose_create',
        'summary' => 'Add',
        'payload' => ['first_name' => 'X'],
        'status' => ProposalStatus::Pending,
        'expires_at' => now()->addDay(),
    ]));
});

it('isolates automation receipts per workspace', function () {
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'ai.text.generate',
        'idempotency_key' => 'k',
        'output' => ['text' => 'x'],
    ]));
});

it('stores a proposal payload encrypted', function () {
    $tenant = $this->createTenant('ai-enc');

    $this->inTenant($tenant, function (): void {
        $proposal = Proposal::query()->create([
            'conversation_id' => aiMakeConversation()->id,
            'membership_id' => aiOwnerMembership()->id,
            'tool' => 'contacts_propose_create',
            'summary' => 'Add',
            'payload' => ['first_name' => 'PlainName'],
            'status' => ProposalStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $raw = DB::table('ai_proposals')->where('id', $proposal->id)->where('tenant_id', $proposal->tenant_id)->value('payload');
        expect($raw)->not->toContain('PlainName');
    });
});

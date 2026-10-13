<?php

declare(strict_types=1);

use App\Modules\Communication\Models\Interaction;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\ConversationTimeline;
use App\Modules\WhatsappMarketing\Actions\SettingsManager;
use App\Modules\WhatsappMarketing\Actions\WhatsappAccess;
use App\Modules\WhatsappMarketing\Jobs\BackfillConversationTimeline;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

beforeEach(function () {
    $this->tenant = $this->waTenant('wa-timeline');
    $this->waConnectCloud($this->tenant);
});

/** @return list<string> */
function timelineBodies(string $contactId): array
{
    return Interaction::query()->where('contact_id', $contactId)->where('type', 'whatsapp')->orderBy('occurred_at')->orderBy('id')->pluck('body')->all();
}

it('puts the first message from an unknown number on the timeline of the contact created for it', function () {
    $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['auto_create_contacts' => true]));

    $this->waInbound($this->tenant, '+8801999000010', 'Hello there', 'wamid.TL-1', name: 'New Buyer')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('phone', '+8801999000010')->firstOrFail();
        $message = Message::query()->firstOrFail();
        $entry = Interaction::query()->where('contact_id', $conversation->contact_id)->sole();

        expect($entry->body)->toBe('Hello there')
            ->and($entry->direction)->toBe('inbound')
            ->and($entry->source_module)->toBe('whatsapp_marketing')
            ->and($entry->dedupe_key)->toBe('whatsapp:inbound:'.$message->id);
    });
});

it('leaves a known sender to the platform and adds no second entry', function () {
    $contact = $this->waContact($this->tenant, ['phone' => '+880 1711-000001']);

    $this->waInbound($this->tenant, '+8801711000001', 'Is it in stock?', 'wamid.TL-2')->assertOk();

    $this->inTenant($this->tenant, function () use ($contact): void {
        $entry = Interaction::query()->where('contact_id', $contact->id)->sole();

        expect($entry->source_module)->toBe('communication')->and($entry->body)->toBe('Is it in stock?');
    });
});

it('copies earlier messages once when a person links the conversation to a contact', function () {
    $this->waInbound($this->tenant, '+8801999000011', 'first', 'wamid.TL-3')->assertOk();
    $this->waInbound($this->tenant, '+8801999000011', 'second', 'wamid.TL-4')->assertOk();
    $contact = $this->waContact($this->tenant, ['phone' => '+8801999000011']);

    $this->inTenant($this->tenant, function () use ($contact): void {
        $conversation = Conversation::query()->where('phone', '+8801999000011')->firstOrFail();
        expect($conversation->contact_id)->toBeNull()->and(Interaction::query()->count())->toBe(0);

        app(ConversationManager::class)->linkContact($conversation, $contact);
        app(ConversationManager::class)->linkContact($conversation->refresh(), $contact);
        (new BackfillConversationTimeline($conversation->id, $contact->id))->handle(app(ConversationTimeline::class), app(WhatsappAccess::class));

        expect(timelineBodies($contact->id))->toBe(['first', 'second']);
    });

    $this->waInbound($this->tenant, '+8801999000011', 'third', 'wamid.TL-5')->assertOk();

    $this->inTenant($this->tenant, fn () => expect(timelineBodies($contact->id))->toBe(['first', 'second', 'third']));
});

it('copies earlier messages when a person creates the contact from the conversation', function () {
    $this->waInbound($this->tenant, '+8801999000012', 'can you call me', 'wamid.TL-6', name: 'Karim')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $conversation = Conversation::query()->where('phone', '+8801999000012')->firstOrFail();
        $contact = app(ConversationManager::class)->createContact($conversation);

        expect(timelineBodies($contact->id))->toBe(['can you call me']);
    });
});

it('copies earlier messages when automatic contacts are switched on after a number already wrote', function () {
    $this->waInbound($this->tenant, '+8801999000013', 'before', 'wamid.TL-7')->assertOk();
    $this->inTenant($this->tenant, fn () => app(SettingsManager::class)->update(['auto_create_contacts' => true]));
    // The back-filled entry is written after the live one, so only its own message time can put it first.
    $this->travel(1)->minutes();
    $this->waInbound($this->tenant, '+8801999000013', 'after', 'wamid.TL-8')->assertOk();

    $this->inTenant($this->tenant, function (): void {
        $contactId = Conversation::query()->where('phone', '+8801999000013')->firstOrFail()->contact_id;

        expect($contactId)->not->toBeNull()->and(timelineBodies($contactId))->toBe(['before', 'after']);
    });
});

it('stops when the conversation was linked to someone else before the job ran', function () {
    $this->waInbound($this->tenant, '+8801999000014', 'hello', 'wamid.TL-9')->assertOk();
    $first = $this->waContact($this->tenant, ['phone' => '+8801999000014', 'email' => 'first@example.test']);
    $second = $this->waContact($this->tenant, ['phone' => '+8801999000014', 'email' => 'second@example.test']);

    $this->inTenant($this->tenant, function () use ($first, $second): void {
        $conversation = Conversation::query()->where('phone', '+8801999000014')->firstOrFail();
        $conversation->forceFill(['contact_id' => $second->id])->save();

        app(ConversationTimeline::class)->backfill($conversation->id, $first->id);

        expect(timelineBodies($first->id))->toBe([]);
    });
});

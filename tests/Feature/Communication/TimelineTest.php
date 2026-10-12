<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Contacts\ContactMerger;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Interaction;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-tl');
    [$this->user, $this->membership] = $this->commMember($this->tenant, [
        'communication.timeline.view', 'communication.timeline.log', 'core.contacts.view',
    ], ['core.contacts.view' => 'own', 'communication.timeline.log' => 'own']);
    $this->mine = $this->commContact($this->tenant, [], $this->membership);
    $this->theirs = $this->commContact($this->tenant);
});

it('lets another module append to the timeline through the core contract', function () {
    $recorder = $this->inTenant($this->tenant, fn () => app(InteractionRecorder::class));

    $first = $this->inTenant($this->tenant, fn () => $recorder->record(new InteractionEntry(
        type: 'system', summary: 'Invoice INV-0001 sent', contactId: $this->mine->id, sourceModule: 'invoicing',
        sourceType: 'invoice', sourceId: 'abc', dedupeKey: 'invoice:abc:sent', direction: 'outbound',
    )));
    $again = $this->inTenant($this->tenant, fn () => $recorder->record(new InteractionEntry(
        type: 'system', summary: 'Invoice INV-0001 sent', contactId: $this->mine->id, dedupeKey: 'invoice:abc:sent',
    )));

    expect($first)->toBeTrue()->and($again)->toBeFalse()
        ->and($this->inTenant($this->tenant, fn () => Interaction::query()->count()))->toBe(1);
});

it('does not record for a contact that belongs to another workspace', function () {
    $other = $this->commTenant('comm-tl-other');
    $foreign = $this->commContact($other);

    $written = $this->inTenant($this->tenant, fn () => app(InteractionRecorder::class)->record(
        new InteractionEntry(type: 'note', summary: 'Hello', contactId: $foreign->id),
    ));

    expect($written)->toBeFalse();
});

it('records nothing when the workspace has no communication app', function () {
    $plain = $this->createTenant('no-comm-tl');
    $contact = $this->commContact($plain);

    $written = $this->inTenant($plain, fn () => app(InteractionRecorder::class)->record(
        new InteractionEntry(type: 'note', summary: 'Hello', contactId: $contact->id),
    ));

    expect($written)->toBeFalse();
});

it('shows a contact timeline only to someone who may see that contact', function () {
    $this->inTenant($this->tenant, fn () => app(InteractionRecorder::class)->record(
        new InteractionEntry(type: 'note', summary: 'Asked for a quote', contactId: $this->mine->id),
    ));

    $this->actingAsMember($this->tenant, $this->user)
        ->get($this->commUrl($this->tenant, "/contacts/{$this->mine->id}/timeline"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Communication/Pages/Timeline/Show', false) // pages are built by the frontend phase; drop `false` then
            ->where('subject.type', 'contact')
            ->has('entries.data', 1)
            ->where('entries.data.0.summary', 'Asked for a quote')
            ->where('canLog', true));

    $this->get($this->commUrl($this->tenant, "/contacts/{$this->theirs->id}/timeline"))->assertNotFound();
});

it('logs a note, a call and an email by hand and removes only hand-logged entries', function () {
    $this->actingAsMember($this->tenant, $this->user);
    $url = $this->commUrl($this->tenant, "/contacts/{$this->mine->id}/timeline");

    foreach (['note', 'call', 'email'] as $type) {
        $this->post($url, ['type' => $type, 'summary' => ucfirst($type).' logged', 'body' => 'Details'])->assertRedirect();
    }

    $this->post($url, ['type' => 'system', 'summary' => 'Fake system entry'])->assertSessionHasErrors('type');
    $this->post($this->commUrl($this->tenant, "/contacts/{$this->theirs->id}/timeline"), ['type' => 'note', 'summary' => 'Nope'])->assertNotFound();

    $entries = $this->inTenant($this->tenant, fn () => Interaction::query()->where('contact_id', $this->mine->id)->get());
    expect($entries)->toHaveCount(3)->and($entries->first()->owner_id)->toBe($this->membership->id);

    $this->delete($this->commUrl($this->tenant, "/timeline/{$entries->first()->id}"))->assertRedirect();

    $system = $this->inTenant($this->tenant, function () {
        app(InteractionRecorder::class)->record(new InteractionEntry(
            type: 'email', summary: 'From a module', contactId: $this->mine->id, sourceModule: 'invoicing',
        ));

        return Interaction::query()->where('source_module', 'invoicing')->sole();
    });
    $this->delete($this->commUrl($this->tenant, "/timeline/{$system->id}"))->assertNotFound();
});

it('limits a company timeline to the contacts the viewer may see', function () {
    $company = $this->commCompany($this->tenant);
    $this->inTenant($this->tenant, function () use ($company): void {
        $this->mine->forceFill(['company_id' => $company->id])->save();
        $this->theirs->forceFill(['company_id' => $company->id])->save();
        $recorder = app(InteractionRecorder::class);
        $recorder->record(new InteractionEntry(type: 'note', summary: 'Company level', companyId: $company->id));
        $recorder->record(new InteractionEntry(type: 'note', summary: 'My contact', contactId: $this->mine->id));
        $recorder->record(new InteractionEntry(type: 'note', summary: 'Their contact', contactId: $this->theirs->id));
    });

    // Company visibility is the actor's contact scope as well; grant company view through the contact permission.
    $this->actingAsOwner($this->tenant)->get($this->commUrl($this->tenant, "/companies/{$company->id}/timeline"))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 3));

    $this->actingAsMember($this->tenant, $this->user)
        ->get($this->commUrl($this->tenant, "/companies/{$company->id}/timeline"))
        ->assertNotFound();
});

it('moves timeline entries, messages and consent to the canonical contact when contacts are merged', function () {
    $this->inTenant($this->tenant, fn () => app(InteractionRecorder::class)->record(
        new InteractionEntry(type: 'note', summary: 'On the duplicate', contactId: $this->theirs->id),
    ));
    $this->optIn($this->tenant, $this->theirs);

    $this->inTenant($this->tenant, fn () => app(ContactMerger::class)->merge($this->mine, $this->theirs, []));

    $this->inTenant($this->tenant, function (): void {
        expect(Interaction::query()->where('contact_id', $this->mine->id)->count())->toBe(1)
            ->and(Consent::query()->where('contact_id', $this->mine->id)->count())->toBe(1);
    });
});

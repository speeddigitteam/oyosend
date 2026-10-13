<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Notes\CustomerRecords\NotesSection;
use App\Modules\Notes\Models\Note;
use App\Modules\Notes\Reporting\NotesByAuthorReport;

beforeEach(function () {
    $this->tenant = $this->createTenant('notes-home');
    $this->entitle($this->tenant, 'notes');
});

it('keeps notes isolated per tenant', function () {
    $this->assertTenantIsolated(Note::class, fn () => Note::query()->create(['title' => 'Note', 'body' => 'Body']));
});

it('hides another workspace\'s note behind a 404', function () {
    $note = $this->inTenant($this->tenant, fn (): Note => Note::query()->create(['title' => 'Private', 'body' => 'Body']));
    $intruder = $this->createTenant('notes-intruder');
    $this->entitle($intruder, 'notes');

    $this->assertNotFoundFromOtherTenant('PUT', "notes/{$note->id}", $this->tenant, ['title' => 'Edited', 'body' => 'Body'], $intruder);
    $this->assertNotFoundFromOtherTenant('DELETE', "notes/{$note->id}", $this->tenant, [], $intruder);
});

it('returns 404 for a workspace that is not entitled to notes', function () {
    $other = $this->createTenant('notes-off');

    $this->actingAsOwner($other)
        ->get($this->tenantUrl($other, '/notes'))
        ->assertNotFound();
});

it('lets an owner create, pin, edit and delete a note', function () {
    $contact = $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->createContact(['first_name' => 'Rahim'], null));

    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, '/notes'), ['title' => 'Follow up', 'body' => 'Call next week', 'is_pinned' => true, 'contact_id' => $contact->id])
        ->assertRedirect();

    $note = $this->inTenant($this->tenant, fn (): Note => Note::query()->sole());
    expect($note->title)->toBe('Follow up')
        ->and($note->is_pinned)->toBeTrue()
        ->and($note->contact_id)->toBe($contact->id)
        ->and($note->owner_id)->not->toBeNull();

    $this->actingAsOwner($this->tenant)
        ->put($this->tenantUrl($this->tenant, "/notes/{$note->id}"), ['title' => 'Follow up soon', 'body' => 'Call tomorrow', 'is_pinned' => false])
        ->assertRedirect();

    expect($this->inTenant($this->tenant, fn (): Note => Note::query()->sole())->title)->toBe('Follow up soon');

    $this->actingAsOwner($this->tenant)
        ->delete($this->tenantUrl($this->tenant, "/notes/{$note->id}"))
        ->assertRedirect();

    expect($this->inTenant($this->tenant, fn (): int => Note::query()->count()))->toBe(0);
});

it('lets a viewer read notes but not create them', function () {
    $role = $this->createRole($this->tenant, 'Note reader', ['notes.notes.view']);
    $viewer = $this->addMember($this->tenant, $role);

    $this->actingAsMember($this->tenant, $viewer)
        ->get($this->tenantUrl($this->tenant, '/notes'))
        ->assertOk();

    $this->actingAsMember($this->tenant, $viewer)
        ->post($this->tenantUrl($this->tenant, '/notes'), ['title' => 'Nope', 'body' => 'Body'])
        ->assertForbidden();
});

it('registers its trigger, report provider and customer section', function () {
    expect(array_keys(app(AutomationRegistry::class)->registeredTriggers()))->toContain('notes.note.created');

    $reports = collect(app()->tagged(ReportProvider::TAG))->map(fn (object $p): string => $p::class);
    expect($reports)->toContain(NotesByAuthorReport::class);

    $sections = collect(app()->tagged(CustomerRecordProvider::TAG))->map(fn (object $p): string => $p::class);
    expect($sections)->toContain(NotesSection::class);
});

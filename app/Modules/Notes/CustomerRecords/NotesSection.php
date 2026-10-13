<?php

declare(strict_types=1);

namespace App\Modules\Notes\CustomerRecords;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerAction;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\Notes\Http\Controllers\NoteController;
use App\Modules\Notes\Models\Note;

final class NotesSection implements CustomerRecordProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'notes.notes', module: 'notes', label: 'Notes', permission: NoteController::VIEW, order: 60);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $notes = $this->records->constrain(Note::query(), $query->user, NoteController::VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $notes)->count();

        return new CustomerSection(
            items: $notes->latest()->limit($query->limit)->get()->map(fn (Note $note): CustomerItem => new CustomerItem(
                id: $note->id,
                title: $note->title ?: str($note->body ?? '')->limit(60)->toString() ?: __('Note'),
                status: $note->is_pinned ? __('Pinned') : __('Note'),
                tone: $note->is_pinned ? 'brand' : 'neutral',
                at: $note->created_at,
                url: route('notes.index', ['contact' => $note->contact_id]),
            ))->values()->all(),
            total: $total,
            actions: $query->user->can(NoteController::MANAGE)
                ? [new CustomerAction('create_note', 'Add note', route('notes.index', ['create_for' => $query->contact->id]))]
                : [],
        );
    }
}

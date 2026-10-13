<?php

declare(strict_types=1);

namespace App\Modules\Notes\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Notes\Events\NoteCreated;
use App\Modules\Notes\Models\Note;
use Illuminate\Support\Facades\DB;

final class NoteManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $owner = null): Note
    {
        return DB::transaction(function () use ($attributes, $owner): Note {
            $this->guardReferences($attributes);

            $note = new Note(['is_pinned' => false, ...$attributes]);
            $note->assignOwner($owner)->save();
            $this->audit->record('notes.note.created', $note, ['is_pinned' => $note->is_pinned]);
            NoteCreated::dispatch($note);

            return $note;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Note $note, array $attributes): Note
    {
        return DB::transaction(function () use ($note, $attributes): Note {
            $this->guardReferences($attributes);
            $note->fill($attributes);

            if ($note->isDirty()) {
                $changed = array_keys($note->getDirty());
                $note->save();
                $this->audit->record('notes.note.updated', $note, ['changed' => $changed]);
            }

            return $note;
        });
    }

    public function delete(Note $note): void
    {
        DB::transaction(function () use ($note): void {
            $this->audit->record('notes.note.deleted', $note);
            $note->delete();
        });
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(array $attributes): void
    {
        if (($attributes['contact_id'] ?? null) !== null) {
            Contact::query()->findOrFail($attributes['contact_id']);
        }

        if (($attributes['company_id'] ?? null) !== null) {
            Company::query()->findOrFail($attributes['company_id']);
        }
    }
}

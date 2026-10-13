<?php

declare(strict_types=1);

namespace App\Modules\Notes\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Notes\Models\Note;

final class NoteCreated extends TenantDomainEvent
{
    public readonly string $noteId;

    public readonly string $title;

    public readonly ?string $contactId;

    public readonly ?string $companyId;

    public readonly ?string $ownerId;

    public readonly bool $isPinned;

    public function __construct(Note $note)
    {
        parent::__construct();
        $this->noteId = $note->id;
        $this->title = $note->title;
        $this->contactId = $note->contact_id;
        $this->companyId = $note->company_id;
        $this->ownerId = $note->owner_id;
        $this->isPinned = $note->is_pinned;
    }

    public static function name(): string
    {
        return 'notes.note.created';
    }

    public function payload(): array
    {
        return [
            'note_id' => $this->noteId,
            'title' => $this->title,
            'contact_id' => $this->contactId,
            'company_id' => $this->companyId,
            'owner_id' => $this->ownerId,
            'is_pinned' => $this->isPinned,
        ];
    }
}

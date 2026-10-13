<?php

declare(strict_types=1);

namespace App\Modules\Notes\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Notes\Events\NoteCreated;

final class NoteCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'notes.note.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.notes.note.created';
    }

    public static function module(): string
    {
        return 'notes';
    }

    public static function event(): string
    {
        return NoteCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'notes.notes.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['note_id', 'title'], 'properties' => [
            'note_id' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'contact_id' => ['type' => ['string', 'null']],
            'company_id' => ['type' => ['string', 'null']],
            'owner_id' => ['type' => ['string', 'null']],
            'is_pinned' => ['type' => 'boolean'],
        ]];
    }
}

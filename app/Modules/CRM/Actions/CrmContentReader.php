<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Identity\Models\User;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\CustomFieldValue;
use App\Modules\CRM\Models\Note;
use App\Modules\CRM\Models\Tag;

final class CrmContentReader
{
    public function __construct(private readonly LinkedRecordVisibility $visibility) {}

    /** @return array<string, mixed> */
    public function for(CrmEntityType $type, string $entityId, ?User $viewer = null): array
    {
        $foreignKey = $type->value.'_id';
        $noteQuery = Note::query()->where($foreignKey, $entityId);

        // A customer page never exposes notes that belong to a lead, deal or
        // activity the viewer cannot reach.
        if ($viewer !== null && in_array($type, [CrmEntityType::Contact, CrmEntityType::Company], true)) {
            $this->visibility->constrain($noteQuery, $viewer);
        }

        $notes = in_array($type, [CrmEntityType::Lead, CrmEntityType::Deal, CrmEntityType::Activity, CrmEntityType::Contact, CrmEntityType::Company], true)
            ? $noteQuery->with('author.user:id,name')->orderByDesc('is_pinned')->latest()->get()->map(fn (Note $note): array => ['id' => $note->id, 'body' => $note->body, 'pinned' => $note->is_pinned, 'author' => $note->author?->user?->name, 'createdAt' => $note->created_at->toIso8601String()])->all()
            : [];
        $tags = Tag::query()->whereHas('assignments', fn ($query) => $query->where('entity_type', $type)->where('entity_id', $entityId))->orderBy('name')->get()->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name, 'colour' => $tag->colour])->all();
        $fields = CustomField::query()->where('entity_type', $type)->where('is_active', true)->orderBy('position')->get();
        $values = CustomFieldValue::query()->where('entity_type', $type)->where('entity_id', $entityId)->get()->keyBy('custom_field_id');
        $customFields = $fields->map(fn (CustomField $field): array => ['id' => $field->id, 'key' => $field->key, 'name' => $field->name, 'type' => $field->type->value, 'required' => $field->is_required, 'options' => $field->options, 'value' => $values->get($field->id)?->value])->all();
        $attachments = Attachment::query()->where('entity_type', $type)->where('entity_id', $entityId)->latest()->get()->map(fn (Attachment $attachment): array => ['id' => $attachment->id, 'name' => $attachment->original_name, 'mimeType' => $attachment->mime_type, 'size' => $attachment->size, 'createdAt' => $attachment->created_at->toIso8601String()])->all();

        $tagOptions = Tag::query()->orderBy('name')->get()->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name, 'colour' => $tag->colour])->all();

        return ['notes' => $notes, 'tags' => $tags, 'tagOptions' => $tagOptions, 'customFields' => $customFields, 'attachments' => $attachments];
    }
}

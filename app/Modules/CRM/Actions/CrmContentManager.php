<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomFieldType;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\CustomFieldValue;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Note;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\Taggable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CrmContentManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly TimelineRecorder $timeline, private readonly TenantStorage $storage) {}

    public function createTag(string $name, ?string $colour = null): Tag
    {
        return DB::transaction(function () use ($name, $colour): Tag {
            /** @var Tag $tag */
            $tag = Tag::query()->create(['name' => $name, 'colour' => $colour]);
            $this->audit->record('crm.tag.created', $tag);

            return $tag;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createCustomField(array $attributes): CustomField
    {
        return DB::transaction(function () use ($attributes): CustomField {
            /** @var CustomField $field */
            $field = CustomField::query()->create($attributes);
            $this->audit->record('crm.custom_field.created', $field, ['entity_type' => $field->entity_type->value, 'type' => $field->type->value]);

            return $field;
        });
    }

    /** @param array{lead_id?: string|null, deal_id?: string|null, activity_id?: string|null, contact_id?: string|null, company_id?: string|null} $links */
    public function addNote(string $body, array $links, ?TenantMembership $author = null, bool $pinned = false): Note
    {
        if (array_filter($links) === []) {
            throw ValidationException::withMessages(['note' => __('Choose a CRM record for the note.')]);
        }

        foreach ([CrmEntityType::Lead, CrmEntityType::Deal, CrmEntityType::Activity, CrmEntityType::Contact, CrmEntityType::Company] as $type) {
            $key = $type->value.'_id';

            if (($links[$key] ?? null) !== null) {
                $this->guardEntity($type, $links[$key]);
            }
        }

        return DB::transaction(function () use ($body, $links, $author, $pinned): Note {
            /** @var Note $note */
            $note = Note::query()->create([...$links, 'author_id' => $author?->id, 'body' => $body, 'is_pinned' => $pinned]);
            $this->timeline->record('crm.note.created', __('Note added.'), $links, $author, ['note_id' => $note->id]);
            $this->audit->record('crm.note.created', $note, ['links' => array_keys(array_filter($links))]);

            return $note;
        });
    }

    /** @param list<string> $tagIds */
    public function syncTags(CrmEntityType $type, string $entityId, array $tagIds): void
    {
        $this->guardEntity($type, $entityId);

        DB::transaction(function () use ($type, $entityId, $tagIds): void {
            $unique = array_values(array_unique($tagIds));
            $found = Tag::query()->whereKey($unique)->pluck('id')->all();

            if (count($found) !== count($unique)) {
                throw ValidationException::withMessages(['tags' => __('One or more tags are unavailable.')]);
            }

            Taggable::query()->where('entity_type', $type)->where('entity_id', $entityId)->delete();

            foreach ($unique as $tagId) {
                Taggable::query()->create(['tag_id' => $tagId, 'entity_type' => $type, 'entity_id' => $entityId]);
            }

            $this->audit->record('crm.tags.updated', null, ['entity_type' => $type->value, 'entity_id' => $entityId, 'tag_ids' => $unique]);
        });
    }

    /** @param array<string, mixed> $values */
    public function setCustomValues(CrmEntityType $type, string $entityId, array $values): void
    {
        $this->guardEntity($type, $entityId);

        DB::transaction(function () use ($type, $entityId, $values): void {
            $fields = CustomField::query()->where('entity_type', $type)->where('is_active', true)->orderBy('position')->get()->keyBy('key');
            $allowed = $fields->keys()->all();
            $rules = ['values' => ['required', 'array', $allowed === [] ? 'size:0' : 'array:'.implode(',', $allowed)]];

            foreach ($fields as $field) {
                $base = $field->is_required ? ['required'] : ['sometimes', 'nullable'];
                $rules['values.'.$field->key] = match ($field->type) {
                    CustomFieldType::Text => [...$base, 'string', 'max:5000'],
                    CustomFieldType::Number => [...$base, 'numeric'],
                    CustomFieldType::Boolean => [...$base, 'boolean'],
                    CustomFieldType::Date => [...$base, 'date_format:Y-m-d'],
                    CustomFieldType::Select => [...$base, 'string', Rule::in($field->options ?? [])],
                };
            }

            /** @var array{values: array<string, mixed>} $validated */
            $validated = Validator::make(['values' => $values], $rules)->validate();

            foreach ($fields as $field) {
                if (! array_key_exists($field->key, $validated['values'])) {
                    continue;
                }

                CustomFieldValue::query()->updateOrCreate(
                    ['custom_field_id' => $field->id, 'entity_type' => $type, 'entity_id' => $entityId],
                    ['value' => $validated['values'][$field->key]],
                );
            }

            $this->audit->record('crm.custom_values.updated', null, ['entity_type' => $type->value, 'entity_id' => $entityId, 'keys' => array_keys($validated['values'])]);
        });
    }

    /** @param array<string, scalar|null> $filters */
    public function createSegment(string $name, CrmEntityType $type, array $filters, ?TenantMembership $creator = null): Segment
    {
        SegmentQuery::validateFilters($type, $filters);

        return DB::transaction(function () use ($name, $type, $filters, $creator): Segment {
            /** @var Segment $segment */
            $segment = Segment::query()->create(['name' => $name, 'entity_type' => $type, 'filters' => $filters, 'is_active' => true, 'created_by' => $creator?->id]);
            $this->audit->record('crm.segment.created', $segment, ['entity_type' => $type->value, 'filters' => array_keys($filters)]);

            return $segment;
        });
    }

    public function attach(CrmEntityType $type, string $entityId, UploadedFile $file, ?TenantMembership $uploader = null): Attachment
    {
        $this->guardEntity($type, $entityId);

        $path = $this->storage->putFile("crm/{$type->value}/{$entityId}", $file);

        try {
            return DB::transaction(function () use ($type, $entityId, $file, $uploader, $path): Attachment {
                /** @var Attachment $attachment */
                $attachment = Attachment::query()->create([
                    'entity_type' => $type,
                    'entity_id' => $entityId,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                    'uploaded_by' => $uploader?->id,
                ]);
                $this->audit->record('crm.attachment.created', $attachment, ['entity_type' => $type->value, 'entity_id' => $entityId, 'mime_type' => $attachment->mime_type, 'size' => $attachment->size]);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);
            throw $exception;
        }
    }

    public function deleteAttachment(Attachment $attachment): void
    {
        $path = $attachment->path;

        DB::transaction(function () use ($attachment): void {
            $this->audit->record('crm.attachment.deleted', $attachment, ['entity_type' => $attachment->entity_type->value, 'entity_id' => $attachment->entity_id]);
            $attachment->delete();
        });

        $this->storage->delete($path);
    }

    private function guardEntity(CrmEntityType $type, string $entityId): void
    {
        match ($type) {
            CrmEntityType::Lead => Lead::query()->findOrFail($entityId),
            CrmEntityType::Deal => Deal::query()->findOrFail($entityId),
            CrmEntityType::Activity => Activity::query()->findOrFail($entityId),
            CrmEntityType::Contact => Contact::query()->findOrFail($entityId),
            CrmEntityType::Company => Company::query()->findOrFail($entityId),
        };
    }
}

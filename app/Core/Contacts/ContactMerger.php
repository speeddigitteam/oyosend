<?php

declare(strict_types=1);

namespace App\Core\Contacts;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Core\Contacts\Events\ContactMerged;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Transactionally collapses one shared contact identity into another. */
final class ContactMerger
{
    public const array FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'address',
        'city',
        'country',
        'notes',
        'company_id',
    ];

    public const array SOURCES = ['canonical', 'duplicate', 'input'];

    /**
     * @param  iterable<ContactMergeReference>  $references
     */
    public function __construct(
        private readonly TenantContext $context,
        private readonly ContactDirectory $directory,
        private readonly AuditLogger $audit,
        #[Tag(ContactMergeReference::class)] private readonly iterable $references,
    ) {}

    /**
     * Fields omitted from `$fields` keep the canonical value.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function merge(Contact $canonical, Contact $duplicate, array $fields): Contact
    {
        $tenantId = $this->context->requireId();

        if ($canonical->tenant_id !== $tenantId || $duplicate->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'duplicate_id' => __('Both contacts must belong to the active workspace.'),
            ]);
        }

        if ($canonical->is($duplicate)) {
            throw ValidationException::withMessages([
                'duplicate_id' => __('A contact cannot be merged into itself.'),
            ]);
        }

        $this->assertSelections($fields);

        return DB::transaction(function () use ($canonical, $duplicate, $fields, $tenantId): Contact {
            /** @var Contact $lockedCanonical */
            $lockedCanonical = Contact::query()->lockForUpdate()->findOrFail($canonical->getKey());
            /** @var Contact $lockedDuplicate */
            $lockedDuplicate = Contact::query()->lockForUpdate()->findOrFail($duplicate->getKey());

            $attributes = $this->resolve($lockedCanonical, $lockedDuplicate, $fields);
            $this->assertEmailAvailable($attributes['email'] ?? $lockedCanonical->email, $lockedCanonical, $lockedDuplicate);

            $referenceCounts = [];

            foreach ($this->references as $reference) {
                foreach ($reference->merge($tenantId, $lockedCanonical->id, $lockedDuplicate->id) as $name => $count) {
                    $referenceCounts[$name] = ($referenceCounts[$name] ?? 0) + $count;
                }
            }

            // The duplicate's typed addresses are the core directory's own reference (ADR 0039).
            $movedAddresses = $this->directory->moveAddresses($lockedDuplicate, $lockedCanonical);

            if ($movedAddresses > 0) {
                $referenceCounts['contact_addresses'] = $movedAddresses;
            }

            $duplicateId = $lockedDuplicate->id;
            $duplicateName = $lockedDuplicate->fullName();

            $this->directory->updateContact($lockedCanonical, $attributes);
            $this->directory->deleteContact($lockedDuplicate);

            $this->audit->record('contact.merged', $lockedCanonical, [
                'duplicate_contact_id' => $duplicateId,
                'duplicate_contact_name' => $duplicateName,
                'field_sources' => array_map(static fn (array $selection): string => $selection['source'], $fields),
                'moved_references' => $referenceCounts,
            ]);

            ContactMerged::dispatch($lockedCanonical, $duplicateId, $duplicateName, array_keys($fields));

            return $lockedCanonical->refresh();
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function assertSelections(array $fields): void
    {
        $unknown = array_diff(array_keys($fields), self::FIELDS);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'fields' => __('One or more contact fields cannot be merged.'),
            ]);
        }

        foreach ($fields as $field => $selection) {
            $keys = array_keys($selection);

            if (array_diff($keys, ['source', 'value']) !== []
                || ! is_string($selection['source'] ?? null)
                || ! in_array($selection['source'], self::SOURCES, true)
                || ($selection['source'] === 'input' && ! array_key_exists('value', $selection))
                || ($selection['source'] !== 'input' && array_key_exists('value', $selection))) {
                throw ValidationException::withMessages([
                    "fields.{$field}" => __('Choose the canonical value, duplicate value, or a new value.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function resolve(Contact $canonical, Contact $duplicate, array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field => $selection) {
            $attributes[$field] = match ($selection['source']) {
                'canonical' => $canonical->getAttribute($field),
                'duplicate' => $duplicate->getAttribute($field),
                'input' => $selection['value'],
                default => throw ValidationException::withMessages([
                    "fields.{$field}" => __('Choose the canonical value, duplicate value, or a new value.'),
                ]),
            };
        }

        if (array_key_exists('email', $attributes) && is_string($attributes['email'])) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        return $attributes;
    }

    private function assertEmailAvailable(?string $email, Contact $canonical, Contact $duplicate): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }

        $exists = Contact::query()
            ->whereKeyNot($canonical->getKey())
            ->whereKeyNot($duplicate->getKey())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'fields.email.value' => __('A contact with this email address already exists.'),
            ]);
        }
    }
}

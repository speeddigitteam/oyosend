<?php

declare(strict_types=1);

namespace App\Core\Contacts\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\ContactDuplicateDetector;
use App\Core\Contacts\ContactMerger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContactMergeController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly ContactDuplicateDetector $duplicates,
        private readonly ContactMerger $merger,
    ) {}

    /** Detect duplicates while entering a new contact. */
    public function detect(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $input = $request->validate([
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'exclude_id' => ['nullable', 'string', 'size:26'],
        ]);

        $exclude = isset($input['exclude_id'])
            ? $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->findOrFail($input['exclude_id'])
            : null;

        return response()->json([
            'data' => $this->present(
                $this->duplicates->find($actor, $input['email'] ?? null, $input['phone'] ?? null, $exclude),
                $actor,
                $exclude,
                $input['email'] ?? null,
                $input['phone'] ?? null,
            ),
        ]);
    }

    /** Detect duplicates of an existing canonical contact. */
    public function candidates(Request $request, Contact $contact): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, ContactController::VIEW, $contact);

        return response()->json([
            'data' => $this->present(
                $this->duplicates->find($actor, $contact->email, $contact->phone, $contact),
                $actor,
                $contact,
                $contact->email,
                $contact->phone,
            ),
        ]);
    }

    public function merge(Request $request, Contact $contact): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $this->records->authorize($actor, ContactController::MANAGE, $contact);

        $input = $request->validate([
            'duplicate_id' => ['required', 'string', 'size:26', Rule::notIn([$contact->id])],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['required', 'array'],
            'fields.*.source' => ['required', 'string', Rule::in(ContactMerger::SOURCES)],
            'fields.*.value' => ['nullable'],
        ]);

        /** @var Contact $duplicate */
        $duplicate = $this->records->constrain(Contact::query(), $actor, ContactController::MANAGE)
            ->findOrFail($input['duplicate_id']);
        $this->records->authorize($actor, ContactController::DELETE, $duplicate);

        /** @var array<string, array{source: string, value?: mixed}> $fields */
        $fields = $input['fields'] ?? [];
        $fields = $this->validateInputValues($fields, $actor);

        $this->merger->merge($contact, $duplicate, $fields);

        return back();
    }

    /**
     * @param  array<string, array{source: string, value?: mixed}>  $fields
     * @return array<string, array{source: string, value?: mixed}>
     */
    private function validateInputValues(array $fields, User $actor): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'company_id' => ['nullable', 'string', 'size:26'],
        ];

        foreach ($fields as $field => &$selection) {
            if (! in_array($field, ContactMerger::FIELDS, true)) {
                throw ValidationException::withMessages([
                    'fields' => __('One or more contact fields cannot be merged.'),
                ]);
            }

            if ($selection['source'] !== 'input') {
                continue;
            }

            if (! array_key_exists('value', $selection)) {
                throw ValidationException::withMessages([
                    "fields.{$field}.value" => __('Enter a value for this contact field.'),
                ]);
            }

            $validated = Validator::make(
                ['value' => $selection['value']],
                ['value' => $rules[$field]],
            )->validate();

            if ($field === 'email' && is_string($validated['value'])) {
                $validated['value'] = mb_strtolower($validated['value']);
            }

            if ($field === 'company_id' && $validated['value'] !== null) {
                $validated['value'] = $this->records
                    ->constrain(Company::query(), $actor, ContactController::VIEW)
                    ->findOrFail($validated['value'])
                    ->getKey();
            }

            $selection['value'] = $validated['value'];
        }
        unset($selection);

        return $fields;
    }

    /**
     * @param  iterable<Contact>  $contacts
     * @return list<array<string, mixed>>
     */
    private function present(
        iterable $contacts,
        User $actor,
        ?Contact $canonical,
        ?string $email,
        ?string $phone,
    ): array {
        $normalizedEmail = $this->duplicates->normalizedEmail($email);
        $normalizedPhone = $this->duplicates->normalizedPhone($phone);
        $rows = [];

        foreach ($contacts as $contact) {
            $reasons = [];

            if ($normalizedEmail !== null && $this->duplicates->normalizedEmail($contact->email) === $normalizedEmail) {
                $reasons[] = 'email';
            }

            if ($normalizedPhone !== null && $this->duplicates->normalizedPhone($contact->phone) === $normalizedPhone) {
                $reasons[] = 'phone';
            }

            $rows[] = [
                'id' => $contact->id,
                'name' => $contact->fullName(),
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'jobTitle' => $contact->job_title,
                'address' => $contact->address,
                'city' => $contact->city,
                'country' => $contact->country,
                'notes' => $contact->notes,
                'company' => $contact->company?->only(['id', 'name']),
                'owner' => $contact->owner === null ? null : [
                    'id' => $contact->owner->id,
                    'name' => $contact->owner->user->name,
                ],
                'matchReasons' => $reasons,
                'mergeable' => $canonical !== null
                    && $this->records->allows($actor, ContactController::MANAGE, $canonical)
                    && $this->records->allows($actor, ContactController::MANAGE, $contact)
                    && $this->records->allows($actor, ContactController::DELETE, $contact),
            ];
        }

        return $rows;
    }
}

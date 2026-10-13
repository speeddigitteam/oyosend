<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Ai\ProposalDraft;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Modules\Ai\Support\ChangeValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Proposes changes to fields of an existing contact. Nothing is written until a person confirms. */
final class ContactProposeUpdateTool implements ProposalTool
{
    public function __construct(
        private readonly ContactInput $validator,
        private readonly ContactDirectory $directory,
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    public static function name(): string
    {
        return 'contacts_propose_update';
    }

    public static function dataScope(): string
    {
        return 'contacts';
    }

    public static function permission(): string
    {
        return 'core.contacts.manage';
    }

    public static function description(): string
    {
        return 'Propose changing fields of an existing contact (name, email, phone, job title, city, notes, company). This only creates a proposal that the member must confirm; nothing changes until then.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'changes'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Contact id from a search result.'],
                'changes' => [
                    'type' => 'object',
                    'properties' => [
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'job_title' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                        'company_id' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $contact = $this->contact((string) ($input['id'] ?? ''), $context);
        $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
        $attributes = $this->validator->validate($changes, $context->user, $contact->id);

        if ($attributes === []) {
            throw ValidationException::withMessages(['changes' => 'No changes given.']);
        }

        return new ToolOutput(
            ['status' => 'validated', 'fields' => array_keys($attributes)],
            [$contact->id],
            new ProposalDraft(
                __('Update contact ":name": :fields', ['name' => $contact->fullName(), 'fields' => implode(', ', array_keys($attributes))]),
                ['id' => $contact->id, 'attributes' => $attributes],
                [$contact->id],
            ),
        );
    }

    public function changes(ToolContext $context, array $payload): array
    {
        try {
            $contact = $this->contact((string) ($payload['id'] ?? ''), $context);
        } catch (ModelNotFoundException|HttpException) {
            return [];
        }

        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $rows = [];

        foreach (ContactInput::FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'old' => ChangeValue::display($contact->getAttribute($field)),
                'new' => ChangeValue::display($attributes[$field]),
            ];
        }

        return $rows;
    }

    public function apply(ToolContext $context, array $payload): array
    {
        abort_unless($this->access->allows($context->user, self::permission()), 403);

        $contact = $this->contact((string) ($payload['id'] ?? ''), $context);
        $attributes = $this->validator->validate(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            $context->user,
            $contact->id,
        );

        $this->directory->updateContact($contact, $attributes);

        return ['contact_id' => $contact->id];
    }

    /** A contact the member may both see and manage: otherwise 404, never a hint it exists. */
    private function contact(string $id, ToolContext $context): Contact
    {
        $contact = $this->records->constrain(Contact::query(), $context->user, 'core.contacts.view')->findOrFail($id);
        $this->records->authorize($context->user, self::permission(), $contact);

        return $contact;
    }
}

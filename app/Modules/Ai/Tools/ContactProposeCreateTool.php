<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\AccessChecker;
use App\Core\Ai\Contracts\ProposalTool;
use App\Core\Ai\ProposalDraft;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\ContactDirectory;
use App\Modules\Ai\Support\ChangeValue;

/** Proposes a new contact. Nothing is written until a person confirms. */
final class ContactProposeCreateTool implements ProposalTool
{
    public function __construct(
        private readonly ContactInput $validator,
        private readonly ContactDirectory $directory,
        private readonly AccessChecker $access,
    ) {}

    public static function name(): string
    {
        return 'contacts_propose_create';
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
        return 'Propose adding a new contact to the directory. This only creates a proposal that the member must confirm; nothing is created until then.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['first_name'],
            'properties' => [
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'job_title' => ['type' => 'string'],
                'city' => ['type' => 'string'],
                'company_id' => ['type' => 'string', 'description' => 'Company id from a company search.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $attributes = $this->validator->validate($input, $context->user, creating: true);
        $name = trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? ''));
        $company = isset($attributes['company_id']) ? [(string) $attributes['company_id']] : [];

        return new ToolOutput(
            ['status' => 'validated'],
            $company,
            new ProposalDraft(__('Add contact ":name"', ['name' => $name]), $attributes, $company),
        );
    }

    public function changes(ToolContext $context, array $payload): array
    {
        $rows = [];

        foreach (ContactInput::FIELDS as $field) {
            $new = ChangeValue::display($payload[$field] ?? null);

            if ($new !== null) {
                $rows[] = ['field' => $field, 'old' => null, 'new' => $new];
            }
        }

        return $rows;
    }

    public function apply(ToolContext $context, array $payload): array
    {
        abort_unless($this->access->allows($context->user, self::permission()), 403);

        $attributes = $this->validator->validate($payload, $context->user, creating: true);
        $contact = $this->directory->createContact($attributes, $context->membership);

        return ['contact_id' => $contact->id];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\RecordAccess;
use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\Models\Contact;

/** Reads one contact, if it is inside the record scope of the member. */
final class ContactGetTool implements AiTool
{
    public function __construct(private readonly RecordAccess $records) {}

    public static function name(): string
    {
        return 'contacts_get';
    }

    public static function dataScope(): string
    {
        return 'contacts';
    }

    public static function permission(): string
    {
        return 'core.contacts.view';
    }

    public static function description(): string
    {
        return 'Read one contact by id: name, email, phone, job title, company, address and notes.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string', 'description' => 'Contact id from a search result.']],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $contact = $this->records->constrain(Contact::query(), $context->user, self::permission())
            ->with('company:id,name')
            ->findOrFail((string) ($input['id'] ?? ''));

        return new ToolOutput([
            'id' => $contact->id,
            'name' => $contact->fullName(),
            'email' => $contact->email,
            'phone' => $contact->phone,
            'job_title' => $contact->job_title,
            'company' => $contact->company?->name,
            'address' => $contact->address,
            'city' => $contact->city,
            'country' => $contact->country,
            'notes' => $contact->notes,
        ], [$contact->id]);
    }
}

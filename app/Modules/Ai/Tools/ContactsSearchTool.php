<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\RecordAccess;
use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;

/** Finds contacts in the shared directory, narrowed to the record scope of the member. */
final class ContactsSearchTool implements AiTool
{
    public function __construct(private readonly RecordAccess $records) {}

    public static function name(): string
    {
        return 'contacts_search';
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
        return 'Search the people in the contact directory by name, email or phone. Returns at most 10 matches the member may see.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Part of a name, email or phone number. Empty lists the first contacts.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $search = trim(mb_substr((string) ($input['query'] ?? ''), 0, 100));
        $limit = max(1, min(10, (int) ($input['limit'] ?? 10)));

        $contacts = $this->records->constrain(Contact::query(), $context->user, self::permission())
            ->with('company:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('first_name', 'like', Like::contains($search))
                ->orWhere('last_name', 'like', Like::contains($search))
                ->orWhereRaw('concat_ws(\' \', first_name, last_name) like ?', [Like::contains($search)])
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('phone', 'like', Like::contains($search))))
            ->orderBy('first_name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return new ToolOutput(
            [
                'count' => $contacts->count(),
                'items' => $contacts->map(static fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => $contact->fullName(),
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'job_title' => $contact->job_title,
                    'company' => $contact->company?->name,
                ])->all(),
            ],
            $contacts->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\RecordAccess;
use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\Models\Company;
use App\Core\Support\Like;

/** Finds companies in the shared directory, narrowed to the record scope of the member. */
final class CompaniesSearchTool implements AiTool
{
    public function __construct(private readonly RecordAccess $records) {}

    public static function name(): string
    {
        return 'companies_search';
    }

    public static function dataScope(): string
    {
        return 'companies';
    }

    public static function permission(): string
    {
        return 'core.contacts.view';
    }

    public static function description(): string
    {
        return 'Search the organisations in the directory by name, industry or email. Returns at most 10 matches the member may see.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Part of a name, industry or email.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $search = trim(mb_substr((string) ($input['query'] ?? ''), 0, 100));
        $limit = max(1, min(10, (int) ($input['limit'] ?? 10)));

        $companies = $this->records->constrain(Company::query(), $context->user, self::permission())
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('industry', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return new ToolOutput(
            [
                'count' => $companies->count(),
                'items' => $companies->map(static fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'industry' => $company->industry,
                    'email' => $company->email,
                    'phone' => $company->phone,
                    'city' => $company->city,
                ])->all(),
            ],
            $companies->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Core\Access\RecordAccess;
use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Core\Contacts\Models\Company;

/** Reads one company, if it is inside the record scope of the member. */
final class CompanyGetTool implements AiTool
{
    public function __construct(private readonly RecordAccess $records) {}

    public static function name(): string
    {
        return 'companies_get';
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
        return 'Read one company by id: name, industry, email, phone, website, address and notes.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string', 'description' => 'Company id from a search result.']],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $company = $this->records->constrain(Company::query(), $context->user, self::permission())
            ->findOrFail((string) ($input['id'] ?? ''));

        return new ToolOutput([
            'id' => $company->id,
            'name' => $company->name,
            'industry' => $company->industry,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'address' => $company->address,
            'city' => $company->city,
            'country' => $company->country,
            'notes' => $company->notes,
        ], [$company->id]);
    }
}

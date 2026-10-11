<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Automation\ActionReceiptStore;
use Illuminate\Validation\ValidationException;

final class CreateProjectAction implements ActionDefinition
{
    public function __construct(private readonly ProjectManager $projects, private readonly ActionReceiptStore $receipts) {}

    public static function key(): string
    {
        return 'projects.project.create';
    }

    public static function label(): string
    {
        return 'automation.actions.projects.project.create';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function requiredPermission(): string
    {
        return 'projects.projects.edit';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['name', 'owner_id'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'description' => ['type' => ['string', 'null'], 'maxLength' => 10000], 'owner_id' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'category_id' => ['type' => ['string', 'null']], 'template_id' => ['type' => ['string', 'null']], 'starts_on' => ['type' => ['string', 'null'], 'format' => 'date'], 'due_on' => ['type' => ['string', 'null'], 'format' => 'date']], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['project_id'], 'properties' => ['project_id' => ['type' => 'string']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        $input['name'] = trim((string) ($input['name'] ?? ''));

        if ($input['name'] === '') {
            throw ValidationException::withMessages(['name' => __('The project name is required.')]);
        }

        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $owner = TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
            unset($input['owner_id']);
            $project = $this->projects->create($input, $owner);

            return ['project_id' => $project->id];
        });
    }
}

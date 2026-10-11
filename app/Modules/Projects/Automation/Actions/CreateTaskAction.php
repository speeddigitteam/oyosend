<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Automation\ActionReceiptStore;
use App\Modules\Projects\Models\Project;
use Illuminate\Validation\ValidationException;

final class CreateTaskAction implements ActionDefinition
{
    public function __construct(private readonly TaskManager $tasks, private readonly ActionReceiptStore $receipts) {}

    public static function key(): string
    {
        return 'projects.task.create';
    }

    public static function label(): string
    {
        return 'automation.actions.projects.task.create';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function requiredPermission(): string
    {
        return 'projects.tasks.edit';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['project_id', 'title'], 'properties' => ['project_id' => ['type' => 'string'], 'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240], 'description' => ['type' => ['string', 'null'], 'maxLength' => 20000], 'owner_id' => ['type' => ['string', 'null']], 'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']], 'due_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'estimated_minutes' => ['type' => 'integer', 'minimum' => 0]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['task_id', 'project_id'], 'properties' => ['task_id' => ['type' => 'string'], 'project_id' => ['type' => 'string']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        $input['title'] = trim((string) ($input['title'] ?? ''));

        if ($input['title'] === '') {
            throw ValidationException::withMessages(['title' => __('The task title is required.')]);
        }

        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $project = Project::query()->findOrFail($input['project_id']);
            $owner = ($input['owner_id'] ?? null) === null ? null : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
            unset($input['project_id'], $input['owner_id']);
            $input['automation_key'] = $idempotencyKey;
            $task = $this->tasks->create($project, $input, $owner);

            return ['task_id' => $task->id, 'project_id' => $project->id];
        });
    }
}

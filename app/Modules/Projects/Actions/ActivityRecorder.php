<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Models\ActivityHistory;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use Illuminate\Validation\ValidationException;

final class ActivityRecorder
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, scalar|null> $metadata */
    public function record(string $projectId, string $event, string $summary, ?TenantMembership $actor = null, ?string $taskId = null, array $metadata = []): ActivityHistory
    {
        Project::query()->findOrFail($projectId);

        if ($taskId !== null) {
            Task::query()->where('project_id', $projectId)->findOrFail($taskId);
        }

        if ($actor !== null && ($actor->tenant_id !== $this->context->requireId() || $actor->status !== MembershipStatus::Active)) {
            throw ValidationException::withMessages(['actor' => __('The acting workspace member is unavailable.')]);
        }

        /** @var ActivityHistory $history */
        $history = ActivityHistory::query()->create([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'event' => $event,
            'summary' => $summary,
            'actor_id' => $actor?->id,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        return $history;
    }
}

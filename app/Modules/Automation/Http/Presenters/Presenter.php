<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Presenters;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Automation\Engine\Redactor;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use Illuminate\Support\Collection;

/** Turns models into the plain arrays the Inertia pages receive. Timestamps are ISO 8601 in UTC. */
final class Presenter
{
    /**
     * @param  iterable<string|null>  $ids
     * @return array<string, string> membership id => name
     */
    public static function names(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return TenantMembership::query()->whereIn('id', $ids->all())->with('user')->get()
            ->mapWithKeys(static fn (TenantMembership $member): array => [$member->id => $member->user->name])
            ->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public static function workflow(Workflow $workflow, array $names = []): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status->value,
            'triggerKey' => $workflow->trigger_key,
            'publishedVersionId' => $workflow->published_version_id,
            'actor' => $workflow->actor_membership_id === null ? null : ['id' => $workflow->actor_membership_id, 'name' => $names[$workflow->actor_membership_id] ?? null],
            'hasWebhook' => $workflow->webhook_token_hash !== null,
            'nextRunAt' => $workflow->next_run_at?->toIso8601String(),
            'lastRunAt' => $workflow->last_run_at?->toIso8601String(),
            'lastRunStatus' => $workflow->last_run_status,
            'publishedAt' => $workflow->published_at?->toIso8601String(),
            'updatedAt' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public static function version(WorkflowVersion $version, array $names = [], bool $withGraph = false): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'status' => $version->status->value,
            'hash' => $version->graph_hash,
            'triggerKey' => $version->trigger_key,
            'note' => $version->note,
            'publishedBy' => $version->published_by_membership_id === null ? null : ($names[$version->published_by_membership_id] ?? null),
            'publishedAt' => $version->published_at?->toIso8601String(),
            'archivedAt' => $version->archived_at?->toIso8601String(),
            'updatedAt' => $version->updated_at?->toIso8601String(),
            ...($withGraph ? ['graph' => $version->graph] : []),
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public static function run(Run $run, array $names = [], ?string $workflowName = null): array
    {
        return [
            'id' => $run->id,
            'workflowId' => $run->workflow_id,
            'workflowName' => $workflowName,
            'versionNumber' => $run->version_number,
            'mode' => $run->mode->value,
            'source' => $run->source->value,
            'status' => $run->status->value,
            'triggerKey' => $run->trigger_key,
            'depth' => $run->depth,
            'parentRunId' => $run->parent_run_id,
            'startedBy' => $run->started_by_membership_id === null ? null : ($names[$run->started_by_membership_id] ?? null),
            'stepsExecuted' => $run->steps_executed,
            'retryCount' => $run->retry_count,
            'errorCode' => $run->error_code,
            'errorMessage' => $run->error_message,
            'cancelRequested' => $run->cancel_requested,
            'resumeAt' => $run->resume_at?->toIso8601String(),
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'createdAt' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, RunStep>  $steps
     * @return list<array<string, mixed>>
     */
    public static function steps(Collection $steps, Graph $graph, bool $detail = true): array
    {
        return $steps->map(static function (RunStep $step) use ($graph, $detail): array {
            $node = $graph->node($step->node_id);

            return [
                'id' => $step->id,
                'nodeId' => $step->node_id,
                'nodeType' => $step->node_type,
                'label' => $node !== null && $node['label'] !== '' ? $node['label'] : null,
                'status' => $step->status->value,
                'attempt' => $step->attempt,
                'sequence' => $step->sequence,
                'iteration' => $step->iteration,
                'input' => $detail ? $step->input : null,
                'output' => $detail ? $step->output : null,
                'logs' => $detail ? ($step->logs ?? []) : [],
                'errorCode' => $step->error_code,
                'errorMessage' => $step->error_message,
                'availableAt' => $step->available_at?->toIso8601String(),
                'resumeAt' => $step->resume_at?->toIso8601String(),
                'startedAt' => $step->started_at?->toIso8601String(),
                'finishedAt' => $step->finished_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public static function approval(Approval $approval, array $names = [], ?string $workflowName = null): array
    {
        return [
            'id' => $approval->id,
            'runId' => $approval->run_id,
            'workflowId' => $approval->workflow_id,
            'workflowName' => $workflowName,
            'title' => $approval->title,
            'message' => $approval->message,
            'status' => $approval->status->value,
            'approverType' => $approval->approver_type,
            'approverPermission' => $approval->approver_permission,
            'approverName' => $approval->approver_membership_id === null ? null : ($names[$approval->approver_membership_id] ?? null),
            'requestedBy' => $approval->requested_by_membership_id === null ? null : ($names[$approval->requested_by_membership_id] ?? null),
            'preventSelfApproval' => $approval->prevent_self_approval,
            'onTimeout' => $approval->on_timeout,
            'decidedBy' => $approval->decided_by_membership_id === null ? null : ($names[$approval->decided_by_membership_id] ?? null),
            'decisionComment' => $approval->decision_comment,
            'decidedAt' => $approval->decided_at?->toIso8601String(),
            'expiresAt' => $approval->expires_at?->toIso8601String(),
            'createdAt' => $approval->created_at?->toIso8601String(),
        ];
    }

    /**
     * The signing secret is write-only: only whether one is set is ever sent.
     *
     * @return array<string, mixed>
     */
    public static function endpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'hasSecret' => $endpoint->secret !== null && $endpoint->secret !== '',
            'active' => $endpoint->is_active,
            'lastCalledAt' => $endpoint->last_called_at?->toIso8601String(),
            'lastStatusCode' => $endpoint->last_status_code,
        ];
    }

    /**
     * The trigger payload of a run, with secret-looking values masked.
     *
     * @return array<array-key, mixed>
     */
    public static function payload(Run $run): array
    {
        $trigger = is_array($run->context) && is_array($run->context['trigger'] ?? null) ? $run->context['trigger'] : [];

        return (new Redactor)->redact($trigger);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Automation\Actions\RunManager;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Graph\Graph;
use App\Modules\Automation\Http\Presenters\Presenter;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Actor;
use App\Modules\Automation\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Run history with per-step logs, cancel and retry. */
final class RunController
{
    public function __construct(private readonly Actor $actor, private readonly RunManager $runs) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', RunStatus::values())],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_map(static fn (RunSource $s): string => $s->value, RunSource::cases()))],
            'workflow' => ['nullable', 'string', 'max:26'],
            'mode' => ['nullable', 'string', 'in:'.RunMode::Live->value.','.RunMode::DryRun->value],
        ]);

        $query = Run::query();

        foreach (['status', 'source', 'mode'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        if (($filters['workflow'] ?? '') !== '') {
            $query->where('workflow_id', $filters['workflow']);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $workflowNames = Workflow::query()->whereIn('id', $page->getCollection()->pluck('workflow_id')->unique()->all())->pluck('name', 'id');
        $names = Presenter::names($page->getCollection()->pluck('started_by_membership_id'));

        return Inertia::render('Modules/Automation/Pages/Runs/Index', [
            'runs' => $page->through(static fn (Run $run): array => Presenter::run($run, $names, $workflowNames[$run->workflow_id] ?? null)),
            'filters' => [
                'status' => $filters['status'] ?? '', 'source' => $filters['source'] ?? '',
                'workflow' => $filters['workflow'] ?? '', 'mode' => $filters['mode'] ?? '',
            ],
            'statuses' => RunStatus::values(),
            'workflows' => Workflow::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Workflow $w): array => ['id' => $w->id, 'name' => $w->name])->values()->all(),
            'abilities' => $this->abilities($request),
        ]);
    }

    public function show(Request $request, Run $run): Response
    {
        $workflow = Workflow::query()->find($run->workflow_id);
        $graph = Graph::parse($run->graph ?? WorkflowVersion::query()->findOrFail($run->version_id)->graph)[0];
        $steps = $run->steps()->orderBy('sequence')->orderBy('id')->get();
        $approvals = Approval::query()->where('run_id', $run->id)->orderBy('created_at')->get();
        $names = Presenter::names([
            $run->started_by_membership_id,
            ...$approvals->pluck('approver_membership_id')->all(),
            ...$approvals->pluck('requested_by_membership_id')->all(),
            ...$approvals->pluck('decided_by_membership_id')->all(),
        ]);

        return Inertia::render('Modules/Automation/Pages/Runs/Show', [
            'run' => Presenter::run($run, $names, $workflow?->name),
            'payload' => Presenter::payload($run),
            'steps' => Presenter::steps($steps, $graph),
            'approvals' => $approvals->map(static fn (Approval $approval): array => Presenter::approval($approval, $names))->values()->all(),
            // The exact graph the run executed, for overlaying step statuses on the canvas.
            'graph' => $graph->toArray(),
            'abilities' => [
                ...$this->abilities($request),
                'cancel' => ! $run->status->isTerminal() && $this->actor->can($request, Permissions::RUNS_MANAGE),
                'retry' => $run->status === RunStatus::Failed && ! $run->isDryRun() && $this->actor->can($request, Permissions::RUNS_MANAGE),
            ],
        ]);
    }

    public function cancel(Request $request, Run $run): RedirectResponse
    {
        $this->runs->cancel($run, $this->actor->membership($request));
        Toast::success(__('Run cancelled.'));

        return back();
    }

    public function retry(Request $request, Run $run): RedirectResponse
    {
        $this->runs->retry($run, $this->actor->membership($request));
        Toast::success(__('Run restarted from the failed step.'));

        return back();
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        return [
            'manage' => $this->actor->can($request, Permissions::RUNS_MANAGE),
            'viewWorkflows' => $this->actor->can($request, Permissions::WORKFLOWS_VIEW),
        ];
    }
}

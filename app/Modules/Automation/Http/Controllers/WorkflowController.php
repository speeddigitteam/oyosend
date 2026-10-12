<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Core\Billing\UsageLimits;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Automation\Actions\RunManager;
use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\InvalidWorkflow;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Graph\GraphValidator;
use App\Modules\Automation\Http\Presenters\Catalogue;
use App\Modules\Automation\Http\Presenters\Presenter;
use App\Modules\Automation\Http\Requests\PublishWorkflowRequest;
use App\Modules\Automation\Http\Requests\RunPayloadRequest;
use App\Modules\Automation\Http\Requests\SaveDraftRequest;
use App\Modules\Automation\Http\Requests\StoreWorkflowRequest;
use App\Modules\Automation\Http\Requests\UpdateWorkflowRequest;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Actor;
use App\Modules\Automation\Support\Permissions;
use App\Modules\Automation\Support\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Workflows: list, overview, builder, lifecycle, test and manual runs. */
final class WorkflowController
{
    public function __construct(
        private readonly Actor $actor,
        private readonly WorkflowManager $workflows,
        private readonly RunManager $runs,
        private readonly GraphValidator $validator,
        private readonly Catalogue $catalogue,
        private readonly UsageLimits $limits,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', WorkflowStatus::values())],
        ]);

        $query = Workflow::query()->with('actor.user');

        if (($filters['search'] ?? '') !== '') {
            $query->where('name', 'like', Like::contains($filters['search']));
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        $page = $query->orderByDesc('updated_at')->paginate(25)->withQueryString();
        $names = Presenter::names($page->getCollection()->pluck('actor_membership_id'));

        return Inertia::render('Modules/Automation/Pages/Workflows/Index', [
            'workflows' => $page->through(static fn (Workflow $workflow): array => Presenter::workflow($workflow, $names)),
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? ''],
            'statuses' => WorkflowStatus::values(),
            'templates' => array_map(
                static fn (array $template): array => ['key' => $template['key'], 'name' => $template['name'], 'description' => $template['description']],
                Templates::catalogue(),
            ),
            'usage' => $this->usage(),
            'abilities' => $this->abilities($request),
        ]);
    }

    public function show(Request $request, Workflow $workflow): Response
    {
        $versions = $workflow->versions()->orderByDesc('number')->limit(50)->get();
        $runs = Run::query()->where('workflow_id', $workflow->id)->orderByDesc('created_at')->limit(10)->get();
        $names = Presenter::names([
            $workflow->actor_membership_id,
            ...$versions->pluck('published_by_membership_id')->all(),
            ...$runs->pluck('started_by_membership_id')->all(),
        ]);
        $draft = $versions->firstWhere('status', VersionStatus::Draft);
        $membership = $this->actor->membership($request);

        return Inertia::render('Modules/Automation/Pages/Workflows/Show', [
            'workflow' => Presenter::workflow($workflow, $names),
            'versions' => $versions->map(static fn (WorkflowVersion $version): array => Presenter::version($version, $names))->values()->all(),
            'hasDraft' => $draft !== null,
            'draftIssues' => $draft === null ? [] : $this->validator->validate($draft->graph, $membership, false)->issues(),
            'publishIssues' => $request->session()->get('automation.issues', []),
            'recentRuns' => $runs->map(static fn (Run $run): array => Presenter::run($run, $names))->values()->all(),
            // Shown exactly once, right after the credentials were created or rotated.
            'revealed' => $request->session()->pull('automation.revealed'),
            'webhookPath' => $workflow->webhook_token_hash === null ? null : 'automation/hooks/{token}',
            'abilities' => $this->abilities($request),
        ]);
    }

    public function editor(Request $request, Workflow $workflow): Response
    {
        $membership = $this->actor->membership($request);
        $draft = $workflow->draft() ?? WorkflowVersion::query()->find($workflow->published_version_id);
        abort_if($draft === null, 404);

        $names = Presenter::names([$workflow->actor_membership_id, $draft->published_by_membership_id]);

        return Inertia::render('Modules/Automation/Pages/Workflows/Editor', [
            'workflow' => Presenter::workflow($workflow, $names),
            'version' => Presenter::version($draft, $names, true),
            // A published version is shown read-only; "edit" copies it into a new draft first.
            'readOnly' => $draft->status !== VersionStatus::Draft || $workflow->status === WorkflowStatus::Archived
                || ! $this->actor->can($request, Permissions::WORKFLOWS_MANAGE),
            'issues' => $this->validator->validate($draft->graph, $membership, false)->issues(),
            // What blocked the publish that just redirected back here (flashed by publish()).
            'publishIssues' => $request->session()->get('automation.issues', []),
            'catalogue' => $this->catalogue->forEditor($membership),
            'abilities' => $this->abilities($request),
        ]);
    }

    public function store(StoreWorkflowRequest $request): RedirectResponse
    {
        $membership = $this->actor->membership($request);
        $template = null;

        if (($key = $request->validated('template')) !== null) {
            $template = collect(Templates::catalogue())->firstWhere('key', $key)['graph'] ?? null;
        }

        $workflow = $this->workflows->create(
            (string) $request->validated('name'),
            $request->validated('description'),
            $membership,
            $template,
        );

        Toast::success(__('Workflow created.'));

        return to_route('automation.workflows.editor', $workflow);
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): RedirectResponse
    {
        $this->workflows->update($workflow, $request->validated());
        Toast::success(__('Workflow saved.'));

        return back();
    }

    public function saveDraft(SaveDraftRequest $request, Workflow $workflow): RedirectResponse|JsonResponse
    {
        $membership = $this->actor->membership($request);
        /** @var array<string, mixed> $graph */
        $graph = $request->validated('graph');
        $draft = $this->workflows->saveDraft($workflow, $graph, $membership);

        if ($request->expectsJson()) {
            return response()->json([
                'version' => Presenter::version($draft),
                ...$this->validator->validate($draft->graph, $membership, false)->toArray(),
            ]);
        }

        Toast::success(__('Draft saved.'));

        return back();
    }

    /** Validate a graph without saving it (the one in the body, or the stored draft). */
    public function validateGraph(Request $request, Workflow $workflow): JsonResponse
    {
        $data = $request->validate(['graph' => ['nullable', 'array']]);
        $graph = $data['graph'] ?? $workflow->draft()?->graph;
        abort_if($graph === null, 404);

        return response()->json($this->validator->validate($graph, $this->actor->membership($request), false)->toArray());
    }

    public function publish(PublishWorkflowRequest $request, Workflow $workflow): RedirectResponse
    {
        try {
            $result = $this->workflows->publish($workflow, $this->actor->membership($request), $request->validated('note'));
        } catch (InvalidWorkflow $exception) {
            return back()->withErrors($exception->errors())->with('automation.issues', $exception->report->issues());
        }

        if ($result['credentials'] !== null) {
            $request->session()->flash('automation.revealed', [
                'token' => $result['credentials']['token'],
                'secret' => $result['credentials']['secret'],
                'url' => route('automation.hooks.receive', ['token' => $result['credentials']['token']]),
            ]);
        }

        Toast::success(__('Workflow published.'));

        return to_route('automation.workflows.show', $workflow);
    }

    public function pause(Workflow $workflow): RedirectResponse
    {
        $this->workflows->pause($workflow);
        Toast::success(__('Workflow paused.'));

        return back();
    }

    public function resume(Request $request, Workflow $workflow): RedirectResponse
    {
        try {
            $this->workflows->resume($workflow, $this->actor->membership($request));
        } catch (InvalidWorkflow $exception) {
            return back()->withErrors($exception->errors());
        }

        Toast::success(__('Workflow resumed.'));

        return back();
    }

    public function archive(Workflow $workflow): RedirectResponse
    {
        $this->workflows->archive($workflow);
        Toast::success(__('Workflow archived.'));

        return to_route('automation.workflows.index');
    }

    public function duplicate(Request $request, Workflow $workflow): RedirectResponse
    {
        $copy = $this->workflows->duplicate($workflow, $this->actor->membership($request));
        Toast::success(__('Workflow duplicated.'));

        return to_route('automation.workflows.editor', $copy);
    }

    public function restore(Request $request, Workflow $workflow, WorkflowVersion $version): RedirectResponse
    {
        abort_unless($version->workflow_id === $workflow->id, 404);

        $this->workflows->restore($workflow, $version, $this->actor->membership($request));
        Toast::success(__('Version restored as a draft.'));

        return to_route('automation.workflows.editor', $workflow);
    }

    public function rotateWebhook(Request $request, Workflow $workflow): RedirectResponse
    {
        $credentials = $this->workflows->rotateWebhook($workflow);

        $request->session()->flash('automation.revealed', [
            'token' => $credentials['token'],
            'secret' => $credentials['secret'],
            'url' => route('automation.hooks.receive', ['token' => $credentials['token']]),
        ]);
        Toast::success(__('Webhook credentials replaced. Copy them now: they are shown once.'));

        return back();
    }

    public function test(RunPayloadRequest $request, Workflow $workflow): RedirectResponse
    {
        try {
            $run = $this->runs->test($workflow, $this->actor->membership($request), (array) $request->validated('payload', []));
        } catch (InvalidWorkflow $exception) {
            return back()->withErrors($exception->errors())->with('automation.issues', $exception->report->issues());
        }

        Toast::info(__('Test run started. Nothing was changed.'));

        return to_route('automation.runs.show', $run);
    }

    public function run(RunPayloadRequest $request, Workflow $workflow): RedirectResponse
    {
        $run = $this->runs->startManual($workflow, $this->actor->membership($request), (array) $request->validated('payload', []));
        Toast::success(__('Run started.'));

        return to_route('automation.runs.show', $run);
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        return [
            'manage' => $this->actor->can($request, Permissions::WORKFLOWS_MANAGE),
            'publish' => $this->actor->can($request, Permissions::WORKFLOWS_PUBLISH),
            'start' => $this->actor->can($request, Permissions::RUNS_START),
            'viewRuns' => $this->actor->can($request, Permissions::RUNS_VIEW),
            'manageWebhooks' => $this->actor->can($request, Permissions::WEBHOOKS_MANAGE),
        ];
    }

    /** @return array<string, array{used: int, limit: int|null}> */
    private function usage(): array
    {
        return [
            'workflows' => ['used' => $this->limits->used('automation.workflows'), 'limit' => $this->limits->limit('automation.workflows')],
            'runs' => ['used' => $this->limits->used('automation.runs'), 'limit' => $this->limits->limit('automation.runs')],
        ];
    }
}

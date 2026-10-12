<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Actions\ClientAccessManager;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\PortfolioAnalytics;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskComment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ClientPortalController
{
    private const int TASKS_PER_PAGE = 50;

    public function __construct(private readonly ClientAccessManager $access, private readonly CollaborationManager $collaboration, private readonly PortfolioAnalytics $analytics, private readonly RecordAccess $records, private readonly ProjectControllerSupport $support, private readonly TenantContext $context) {}

    public function grant(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, ProjectController::MEMBERS, $project);
        $input = $request->validate(['contact_id' => ['required', 'string', 'size:26'], 'company_id' => ['nullable', 'string', 'size:26'], 'can_comment' => ['required', 'boolean'], 'can_view_financials' => ['required', 'boolean'], 'expires_at' => ['nullable', 'date_format:Y-m-d\\TH:i']]);

        if ($input['can_view_financials']) {
            $this->records->authorize($actor, ProjectController::FINANCIALS, $project);
        }

        $contact = $this->support->contact($input['contact_id'], $actor) ?? abort(404);
        $company = $this->support->company($input['company_id'] ?? null, $actor);
        $expiresAt = isset($input['expires_at']) ? CarbonImmutable::parse($input['expires_at'], $this->context->require()->timezone)->utc() : null;

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => __('The expiry time must be in the future.')]);
        }
        $grant = $this->access->grant($project, $contact, $company, $input['can_comment'], $input['can_view_financials'], $expiresAt, $this->support->membership($actor));
        Inertia::flash('clientAccessInvitation', ['accessId' => $grant['access']->id, 'url' => route('projects.portal.show', ['token' => $grant['token']])]);
        Toast::success(__('Client access created. Copy the invitation link now; it will not be shown again.'));

        return back();
    }

    public function revoke(Request $request, Project $project, ClientAccess $clientAccess): RedirectResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($clientAccess->project_id === $project->id, 404);
        $this->records->authorize($actor, ProjectController::MEMBERS, $project);
        $this->access->revoke($clientAccess, $this->support->membership($actor));
        Toast::success(__('Client access revoked.'));

        return back();
    }

    public function show(Request $request, string $token): Response
    {
        $access = $this->access->resolve($token);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $project = $access->project;
        $timezone = $this->context->require()->timezone;
        $tasks = Task::query()->where('project_id', $project->id)->where('client_visible', true)->with(['checklistItems', 'comments' => fn ($query) => $query->where('is_internal', false)->with(['author.user:id,name', 'clientAccess.contact:id,first_name,last_name'])])->orderBy('position')->orderBy('created_at')->orderBy('id')->paginate(self::TASKS_PER_PAGE)->withQueryString();

        return Inertia::render('Modules/Projects/Pages/Portal/Show', [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'description' => $project->description, 'status' => $project->status->value, 'progress' => $project->progress, 'startDate' => $project->starts_on?->toDateString(), 'dueDate' => $project->due_on?->toDateString()],
            'tasks' => $tasks->getCollection()->map(fn (Task $task): array => ['id' => $task->id, 'title' => $task->title, 'description' => $task->description, 'status' => $task->status->value, 'priority' => $task->priority->value, 'startDate' => $task->starts_at?->toImmutable()->setTimezone($timezone)->toDateString(), 'dueDate' => $task->due_at?->toImmutable()->setTimezone($timezone)->toDateString(), 'progress' => $task->progress, 'checklist' => $task->checklistItems->map(fn ($item): array => ['id' => $item->id, 'label' => $item->title, 'completed' => $item->is_completed])->all(), 'comments' => $task->comments->map(fn (TaskComment $comment): array => ['id' => $comment->id, 'body' => $comment->body, 'author' => $comment->author?->user->name ?? $comment->clientAccess?->contact->fullName(), 'createdAt' => $comment->created_at->toIso8601String()])->all()])->all(),
            'tasksPagination' => $this->pagination($tasks),
            'client' => ['name' => $access->contact->fullName(), 'canComment' => $access->can_comment],
            'financials' => $access->can_view_financials ? Arr::only($this->analytics->financials($project), ['currency', 'budgetAmount', 'spentAmount', 'budgetRemainingAmount']) : null,
            'token' => $token,
        ]);
    }

    public function comment(Request $request, string $token, Task $task): RedirectResponse
    {
        $access = $this->access->resolve($token);
        abort_unless($access->can_comment && $task->project_id === $access->project_id && $task->client_visible, 404);
        $input = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $this->collaboration->comment($task, $input['body'], null, clientAccessId: $access->id);
        Toast::success(__('Comment added.'));

        return back();
    }

    /**
     * @param  LengthAwarePaginator<int, Task>  $paginator
     * @return array{currentPage: int, perPage: int, total: int, lastPage: int, hasMorePages: bool, previousPageUrl: string|null, nextPageUrl: string|null}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
            'hasMorePages' => $paginator->hasMorePages(),
            'previousPageUrl' => $paginator->previousPageUrl(),
            'nextPageUrl' => $paginator->nextPageUrl(),
        ];
    }
}

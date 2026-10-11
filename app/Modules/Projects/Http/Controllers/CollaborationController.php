<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ChecklistItem;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CollaborationController
{
    public function __construct(private readonly RecordAccess $records, private readonly ProjectControllerSupport $support, private readonly CollaborationManager $collaboration, private readonly TaskManager $tasks, private readonly TenantStorage $storage) {}

    public function comment(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, TaskController::COMMENT, $task);
        $input = $request->validate(['body' => ['required', 'string', 'max:20000'], 'mentions' => ['sometimes', 'array', 'max:50'], 'mentions.*' => ['string', 'size:26', 'distinct'], 'is_internal' => ['sometimes', 'boolean']]);
        $this->collaboration->comment($task, $input['body'], $this->support->membership($actor), $input['mentions'] ?? [], (bool) ($input['is_internal'] ?? false));
        Toast::success(__('Comment added.'));

        return back();
    }

    public function addChecklist(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, TaskController::EDIT, $task);
        $input = $request->validate(['title' => ['required', 'string', 'max:240'], 'position' => ['nullable', 'integer', 'between:0,65535']]);
        $this->collaboration->addChecklist($task, $input['title'], (int) ($input['position'] ?? 0), $this->support->membership($actor));

        return back();
    }

    public function updateChecklist(Request $request, Project $project, Task $task, ChecklistItem $checklist): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        abort_unless($checklist->task_id === $task->id, 404);
        $this->records->authorize($actor, TaskController::EDIT, $task);
        $input = $request->validate(['completed' => ['required', 'boolean']]);
        $this->collaboration->setChecklist($checklist, $input['completed'], $this->support->membership($actor));

        return back();
    }

    public function dependency(Request $request, Project $project, Task $task): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, TaskController::EDIT, $task);
        $input = $request->validate(['depends_on_task_id' => ['required', 'string', 'size:26'], 'type' => ['nullable', 'in:finish_to_start,start_to_start,finish_to_finish,start_to_finish']]);
        $dependsOn = $this->records->constrain(Task::query(), $actor, TaskController::VIEW)->findOrFail($input['depends_on_task_id']);
        $this->tasks->addDependency($task, $dependsOn, $input['type'] ?? 'finish_to_start', $this->support->membership($actor));

        return back();
    }

    public function attach(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,webp'], 'task_id' => ['nullable', 'string', 'size:26']]);
        $actorMembership = $this->support->membership($actor);

        if (($input['task_id'] ?? null) === null) {
            $this->records->authorize($actor, ProjectController::EDIT, $project);
            $this->collaboration->attachProject($project, $request->file('file'), $actorMembership);
        } else {
            $task = Task::query()->findOrFail($input['task_id']);
            $this->guardTask($project, $task);
            $this->records->authorize($actor, TaskController::EDIT, $task);
            $this->collaboration->attachTask($task, $request->file('file'), $actorMembership);
        }

        Toast::success(__('Attachment uploaded.'));

        return back();
    }

    public function download(Request $request, Project $project, Attachment $attachment): Response
    {
        $actor = $this->support->actor($request);
        $this->authorizeAttachment($actor, $project, $attachment, false);
        $contents = $this->storage->get($attachment->path);
        abort_if($contents === null, 404);

        return response($contents, 200, ['Content-Type' => $attachment->mime_type, 'Content-Disposition' => 'attachment; filename="'.addcslashes($attachment->original_name, '"\\').'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Project $project, Attachment $attachment): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->authorizeAttachment($actor, $project, $attachment, true);
        $this->collaboration->deleteAttachment($attachment);
        Toast::success(__('Attachment deleted.'));

        return back();
    }

    private function guardTask(Project $project, Task $task): void
    {
        abort_unless($task->project_id === $project->id, 404);
    }

    private function authorizeAttachment(User $actor, Project $project, Attachment $attachment, bool $edit): void
    {
        if ($attachment->entity_type === 'project') {
            abort_unless($attachment->entity_id === $project->id, 404);
            $this->records->authorize($actor, $edit ? ProjectController::EDIT : ProjectController::VIEW, $project);

            return;
        }

        abort_unless($attachment->entity_type === 'task', 404);
        $task = Task::query()->findOrFail($attachment->entity_id);
        $this->guardTask($project, $task);
        $this->records->authorize($actor, $edit ? TaskController::EDIT : TaskController::VIEW, $task);
    }
}

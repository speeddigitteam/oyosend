<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ChecklistItem;
use App\Modules\Projects\Models\CommentMention;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskComment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CollaborationManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity, private readonly TenantStorage $storage) {}

    /** @param list<string> $mentionIds */
    public function comment(Task $task, string $body, ?TenantMembership $author, array $mentionIds = [], bool $internal = false, ?string $clientAccessId = null): TaskComment
    {
        return DB::transaction(function () use ($task, $body, $author, $mentionIds, $internal, $clientAccessId): TaskComment {
            $unique = array_values(array_unique($mentionIds));
            $mentions = TenantMembership::query()->where('status', MembershipStatus::Active)->whereKey($unique)->pluck('id');

            if ($mentions->count() !== count($unique)) {
                throw ValidationException::withMessages(['mentions' => __('One or more mentioned members are unavailable.')]);
            }

            /** @var TaskComment $comment */
            $comment = TaskComment::query()->create(['task_id' => $task->id, 'author_id' => $author?->id, 'client_access_id' => $clientAccessId, 'body' => $body, 'is_internal' => $internal]);

            foreach ($unique as $membershipId) {
                CommentMention::query()->create(['comment_id' => $comment->id, 'membership_id' => $membershipId, 'created_at' => now()]);
            }

            $this->activity->record($task->project_id, 'projects.task.commented', __('Task comment added.'), $author, $task->id, ['comment_id' => $comment->id, 'mention_count' => count($unique), 'client' => $clientAccessId !== null]);
            $this->audit->record('projects.task.commented', $comment, ['task_id' => $task->id, 'mention_count' => count($unique), 'internal' => $internal, 'client' => $clientAccessId !== null]);

            return $comment;
        });
    }

    public function addChecklist(Task $task, string $title, int $position, ?TenantMembership $actor = null): ChecklistItem
    {
        return DB::transaction(function () use ($task, $title, $position, $actor): ChecklistItem {
            /** @var ChecklistItem $item */
            $item = ChecklistItem::query()->create(['task_id' => $task->id, 'title' => $title, 'position' => $position]);
            $this->activity->record($task->project_id, 'projects.task.checklist_added', __('Checklist item added.'), $actor, $task->id, ['checklist_item_id' => $item->id]);

            return $item;
        });
    }

    public function setChecklist(ChecklistItem $item, bool $completed, ?TenantMembership $actor = null): ChecklistItem
    {
        return DB::transaction(function () use ($item, $completed, $actor): ChecklistItem {
            $item->update(['is_completed' => $completed, 'completed_by' => $completed ? $actor?->id : null, 'completed_at' => $completed ? now() : null]);
            $this->activity->record($item->task->project_id, 'projects.task.checklist_updated', __('Checklist item updated.'), $actor, $item->task_id, ['checklist_item_id' => $item->id, 'completed' => $completed]);

            return $item;
        });
    }

    public function attachProject(Project $project, UploadedFile $file, ?TenantMembership $uploader = null): Attachment
    {
        return $this->attach('project', $project->id, "projects/{$project->id}", $file, $uploader);
    }

    public function attachTask(Task $task, UploadedFile $file, ?TenantMembership $uploader = null): Attachment
    {
        return $this->attach('task', $task->id, "projects/{$task->project_id}/tasks/{$task->id}", $file, $uploader);
    }

    public function deleteAttachment(Attachment $attachment): void
    {
        $path = $attachment->path;
        DB::transaction(function () use ($attachment): void {
            $this->audit->record('projects.attachment.deleted', $attachment, ['entity_type' => $attachment->entity_type, 'entity_id' => $attachment->entity_id]);
            $attachment->delete();
        });
        $this->storage->delete($path);
    }

    private function attach(string $entityType, string $entityId, string $directory, UploadedFile $file, ?TenantMembership $uploader): Attachment
    {
        $path = $this->storage->putFile($directory, $file);

        try {
            return DB::transaction(function () use ($entityType, $entityId, $file, $uploader, $path): Attachment {
                /** @var Attachment $attachment */
                $attachment = Attachment::query()->create(['entity_type' => $entityType, 'entity_id' => $entityId, 'original_name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType() ?? 'application/octet-stream', 'size' => $file->getSize(), 'uploaded_by' => $uploader?->id]);
                $this->audit->record('projects.attachment.created', $attachment, ['entity_type' => $entityType, 'entity_id' => $entityId, 'mime_type' => $attachment->mime_type, 'size' => $attachment->size]);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);
            throw $exception;
        }
    }
}

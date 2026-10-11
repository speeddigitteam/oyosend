<?php

declare(strict_types=1);

use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\ContactMerger;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Enums\ClientAccessStatus;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TaskComment;

it('repoints project contacts and resolves client-access collisions during a core contact merge', function () {
    $tenant = $this->createTenant('projects-contact-merge');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $directory = app(ContactDirectory::class);
        $canonical = $directory->createContact(['first_name' => 'Canonical', 'email' => 'canonical@example.test'], $owner);
        $duplicate = $directory->createContact(['first_name' => 'Duplicate', 'email' => 'duplicate@example.test'], $owner);
        $projects = app(ProjectManager::class);
        $collisionProject = $projects->create(['name' => 'Collision', 'contact_id' => $duplicate->id], $owner, $owner);
        $moveProject = $projects->create(['name' => 'Move only', 'contact_id' => $duplicate->id], $owner, $owner);
        $keptHash = hash('sha256', 'canonical-token');
        $deletedHash = hash('sha256', 'duplicate-token');
        $movedHash = hash('sha256', 'moved-token');

        $canonicalAccess = ClientAccess::query()->create([
            'project_id' => $collisionProject->id,
            'contact_id' => $canonical->id,
            'status' => ClientAccessStatus::Active,
            'token_hash' => $keptHash,
        ]);
        $duplicateAccess = ClientAccess::query()->create([
            'project_id' => $collisionProject->id,
            'contact_id' => $duplicate->id,
            'status' => ClientAccessStatus::Invited,
            'token_hash' => $deletedHash,
        ]);
        ClientAccess::query()->create([
            'project_id' => $moveProject->id,
            'contact_id' => $duplicate->id,
            'status' => ClientAccessStatus::Invited,
            'token_hash' => $movedHash,
        ]);
        $task = app(TaskManager::class)->create($collisionProject, ['title' => 'Client feedback'], $owner, $owner);
        $comment = TaskComment::query()->create([
            'task_id' => $task->id,
            'client_access_id' => $duplicateAccess->id,
            'body' => 'Keep my identity after the merge.',
            'is_internal' => false,
        ]);

        app(ContactMerger::class)->merge($canonical, $duplicate, []);

        expect(Project::query()->where('contact_id', $canonical->id)->count())->toBe(2)
            ->and(Project::query()->where('contact_id', $duplicate->id)->exists())->toBeFalse()
            ->and(ClientAccess::query()->where('contact_id', $canonical->id)->count())->toBe(2)
            ->and(ClientAccess::query()->where('contact_id', $duplicate->id)->exists())->toBeFalse()
            ->and(ClientAccess::query()->where('project_id', $collisionProject->id)->sole()->token_hash)->toBe($keptHash)
            ->and(ClientAccess::query()->where('project_id', $moveProject->id)->sole()->token_hash)->toBe($movedHash)
            ->and(ClientAccess::query()->where('token_hash', $deletedHash)->exists())->toBeFalse()
            ->and($comment->refresh()->client_access_id)->toBe($canonicalAccess->id)
            ->and($comment->clientAccess->contact_id)->toBe($canonical->id);
    });
});

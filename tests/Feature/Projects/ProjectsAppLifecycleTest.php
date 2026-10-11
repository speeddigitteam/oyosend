<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Jobs\MaintainProjectTasks;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(AppCatalogSynchronizer::class)->sync();

    $this->projectsApp = CatalogApp::query()
        ->where('module_key', 'projects')
        ->with('currentVersion')
        ->sole();
});

it('publishes and dispatches the real Projects maintenance schedule', function (): void {
    expect($this->projectsApp->currentVersion?->scheduled_jobs)->toEqual([[
        'key' => 'projects.task_maintenance',
        'job' => MaintainProjectTasks::class,
        'frequency' => ScheduledJobFrequency::EveryMinute->value,
    ]]);

    $tenant = $this->createTenant('projects-schedule-integration');
    $this->inTenant(
        $tenant,
        fn () => app(AppStoreManager::class)->installFree($this->projectsApp, 'included'),
    );
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $tenant->slug])
        ->expectsOutputToContain('Dispatched 1 app job(s), 0 failed.')
        ->assertSuccessful();

    $queued = null;
    Queue::assertPushed(RunScheduledAppJob::class, function (RunScheduledAppJob $job) use (&$queued): bool {
        $queued = $job;

        return $job->module === 'projects'
            && $job->definition === 'projects.task_maintenance';
    });

    expect($queued)->toBeInstanceOf(RunScheduledAppJob::class);

    if (! $queued instanceof RunScheduledAppJob) {
        throw new RuntimeException('The Projects maintenance schedule was not queued.');
    }

    expect($queued->displayName())->toBe(MaintainProjectTasks::class)
        ->and($queued->queue)->toBe('automation')
        ->and($queued->tries)->toBe(3)
        ->and($queued->tags())->toBe([
            'app:projects',
            'schedule:projects.task_maintenance',
        ]);
});

it('retains real Projects records and files across uninstall and reinstall', function (): void {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));

    $tenant = $this->createTenant('projects-retained-reinstall');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function (): void {
        app(AppStoreManager::class)->installFree($this->projectsApp, 'included');
    });

    [$projectId, $taskId, $attachmentId, $attachmentPath] = $this->inTenant(
        $tenant,
        function () use ($owner): array {
            $project = app(ProjectManager::class)->create(
                ['name' => 'Retained delivery history'],
                $owner,
                $owner,
            );
            $task = app(TaskManager::class)->create(
                $project,
                ['title' => 'Retained task'],
                $owner,
                $owner,
            );
            $attachment = app(CollaborationManager::class)->attachProject(
                $project,
                UploadedFile::fake()->create('retained-brief.txt', 1, 'text/plain'),
                $owner,
            );

            return [$project->id, $task->id, $attachment->id, $attachment->path];
        },
    );

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/projects/{$projectId}"))
        ->assertOk();

    $this->inTenant($tenant, function (): void {
        app(AppStoreManager::class)->uninstall($this->projectsApp);
    });

    expect(app(EntitlementChecker::class)->allows($tenant, 'projects'))->toBeFalse();

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/projects/{$projectId}"))
        ->assertNotFound();

    $this->inTenant($tenant, function () use ($projectId, $taskId, $attachmentId, $attachmentPath): void {
        expect(Project::query()->whereKey($projectId)->exists())->toBeTrue()
            ->and(Task::query()->whereKey($taskId)->exists())->toBeTrue()
            ->and(Attachment::query()->whereKey($attachmentId)->exists())->toBeTrue()
            ->and(app(TenantStorage::class)->exists($attachmentPath))->toBeTrue();

        app(AppStoreManager::class)->reinstall($this->projectsApp);
    });

    expect(app(EntitlementChecker::class)->allows($tenant, 'projects'))->toBeTrue();

    $this->actingAsOwner($tenant)
        ->get($this->tenantUrl($tenant, "/projects/{$projectId}"))
        ->assertOk();

    $this->inTenant($tenant, function () use ($projectId, $taskId, $attachmentId, $attachmentPath): void {
        expect(Project::query()->whereKey($projectId)->exists())->toBeTrue()
            ->and(Task::query()->whereKey($taskId)->exists())->toBeTrue()
            ->and(Attachment::query()->whereKey($attachmentId)->exists())->toBeTrue()
            ->and(app(TenantStorage::class)->exists($attachmentPath))->toBeTrue();
    });
});

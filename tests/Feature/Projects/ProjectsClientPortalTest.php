<?php

declare(strict_types=1);

use App\Core\Contacts\ContactDirectory;
use App\Modules\Projects\Actions\ClientAccessManager;
use App\Modules\Projects\Actions\CollaborationManager;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Enums\ClientAccessStatus;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TaskComment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('stores only a hidden hash and accepts a client token until revocation', function () {
    $tenant = $this->createTenant('projects-client-token');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $contact = app(ContactDirectory::class)->createContact(['first_name' => 'Client', 'email' => 'client@example.test'], $owner);
        $project = app(ProjectManager::class)->create(['name' => 'Client project', 'contact_id' => $contact->id], $owner, $owner);
        $manager = app(ClientAccessManager::class);
        $grant = $manager->grant($project, $contact, null, true, false, now()->addDay(), $owner);
        $access = $grant['access'];
        $token = $grant['token'];

        expect($token)->toMatch('/^[a-f0-9]{64}$/')
            ->and($access->token_hash)->toBe(hash('sha256', $token))
            ->and($access->token_hash)->not->toBe($token)
            ->and($access->toArray())->not->toHaveKey('token_hash')
            ->and(json_encode($access))->not->toContain($access->token_hash);

        $resolved = $manager->resolve($token);

        expect($resolved->is($access))->toBeTrue()
            ->and($resolved->status)->toBe(ClientAccessStatus::Active)
            ->and($resolved->accepted_at)->not->toBeNull()
            ->and($manager->resolve($token)->is($access))->toBeTrue();

        $manager->revoke($access, $owner);

        expect($access->refresh()->status)->toBe(ClientAccessStatus::Revoked)
            ->and($access->token_hash)->toBeNull()
            ->and(fn () => $manager->resolve($token))->toThrow(NotFoundHttpException::class);
    });
});

it('expires client access and rejects malformed, foreign-contact, and rotated tokens', function () {
    $tenant = $this->createTenant('projects-client-expiry');
    $foreignTenant = $this->createTenant('projects-client-foreign');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));
    $foreignContact = $this->inTenant($foreignTenant, fn () => app(ContactDirectory::class)->createContact(['first_name' => 'Foreign']));

    $this->inTenant($tenant, function () use ($owner, $foreignContact): void {
        $contact = app(ContactDirectory::class)->createContact(['first_name' => 'Client'], $owner);
        $project = app(ProjectManager::class)->create(['name' => 'Expiring project'], $owner, $owner);
        $manager = app(ClientAccessManager::class);

        expect(fn () => $manager->grant($project, $foreignContact, null, true, false, null, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => $manager->resolve('not-a-token'))->toThrow(NotFoundHttpException::class);

        $first = $manager->grant($project, $contact, null, true, false, now()->addMinute(), $owner);
        $second = $manager->grant($project, $contact, null, false, true, now()->addMinute(), $owner);

        expect($second['access']->id)->toBe($first['access']->id)
            ->and(fn () => $manager->resolve($first['token']))->toThrow(NotFoundHttpException::class);

        $this->travel(2)->minutes();

        expect(fn () => $manager->resolve($second['token']))->toThrow(NotFoundHttpException::class)
            ->and(ClientAccess::query()->sole()->status)->toBe(ClientAccessStatus::Expired)
            ->and(ClientAccess::query()->sole()->token_hash)->toBeNull();
    });
});

it('marks tasks client-visible explicitly rather than exposing every project task', function () {
    $tenant = $this->createTenant('projects-client-visible');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $this->inTenant($tenant, function () use ($owner): void {
        $project = app(ProjectManager::class)->create(['name' => 'Visible work'], $owner, $owner);
        $tasks = app(TaskManager::class);
        $private = $tasks->create($project, ['title' => 'Internal task'], $owner, $owner);
        $visible = $tasks->create($project, ['title' => 'Client task', 'client_visible' => true], $owner, $owner);

        expect($private->client_visible)->toBeFalse()
            ->and($visible->client_visible)->toBeTrue()
            ->and($project->tasks()->where('client_visible', true)->pluck('id')->all())->toBe([$visible->id]);
    });
});

it('redacts private work, internal comments, and financials from the client portal', function () {
    $tenant = $this->createTenant('projects-client-portal-redaction');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    [$project, $visibleTask, $restrictedToken, $financialToken] = $this->inTenant($tenant, function () use ($owner): array {
        $contacts = app(ContactDirectory::class);
        $client = $contacts->createContact(['first_name' => 'Portal'], $owner);
        $financeClient = $contacts->createContact(['first_name' => 'Finance'], $owner);
        $project = app(ProjectManager::class)->create(['name' => 'Published project', 'contact_id' => $client->id, 'budget_amount' => 75_000], $owner, $owner);
        $tasks = app(TaskManager::class);
        $visibleTask = $tasks->create($project, ['title' => 'Published task', 'client_visible' => true], $owner, $owner);
        $tasks->create($project, ['title' => 'Private task'], $owner, $owner);
        $comments = app(CollaborationManager::class);
        $comments->comment($visibleTask, 'Shared update', $owner);
        $comments->comment($visibleTask, 'Internal update', $owner, internal: true);
        $access = app(ClientAccessManager::class);
        $restricted = $access->grant($project, $client, null, true, false, now()->addDay(), $owner);
        $financial = $access->grant($project, $financeClient, null, true, true, now()->addDay(), $owner);

        return [$project, $visibleTask, $restricted['token'], $financial['token']];
    });

    $this->get($this->tenantUrl($tenant, "/project-portal/{$restrictedToken}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Portal/Show')
            ->where('project.id', $project->id)
            ->has('tasks', 1)
            ->where('tasks.0.id', $visibleTask->id)
            ->has('tasks.0.comments', 1)
            ->where('tasks.0.comments.0.body', 'Shared update')
            ->where('client.canComment', true)
            ->where('financials', null));

    $this->get($this->tenantUrl($tenant, "/project-portal/{$financialToken}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('financials.currency', 'BDT')
            ->where('financials.budgetAmount', '75000'));
});

it('returns client-visible portal tasks in stable bounded pages', function () {
    $tenant = $this->createTenant('projects-client-portal-pagination');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    [$token, $orderedIds] = $this->inTenant($tenant, function () use ($owner): array {
        $contact = app(ContactDirectory::class)->createContact(['first_name' => 'Paged'], $owner);
        $project = app(ProjectManager::class)->create(['name' => 'Paged portal'], $owner, $owner);
        $now = now();
        $rows = [];

        for ($index = 1; $index <= 56; $index++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $project->id,
                'title' => sprintf('Visible task %03d', $index),
                'status' => 'backlog',
                'priority' => 'normal',
                'owner_id' => $owner->id,
                'position' => 0,
                'client_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Task::query()->insert([
            ...$rows,
            [
                'id' => (string) Str::ulid(),
                'tenant_id' => $owner->tenant_id,
                'project_id' => $project->id,
                'title' => 'Private task',
                'status' => 'backlog',
                'priority' => 'normal',
                'owner_id' => $owner->id,
                'position' => 0,
                'client_visible' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        $token = app(ClientAccessManager::class)->grant($project, $contact, null, true, false, now()->addDay(), $owner)['token'];
        $orderedIds = collect($rows)->sortBy('id')->pluck('id')->values()->all();

        return [$token, $orderedIds];
    });

    $this->get($this->tenantUrl($tenant, "/project-portal/{$token}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Projects/Pages/Portal/Show')
            ->has('tasks', 50)
            ->where('tasks.0.id', $orderedIds[0])
            ->where('tasksPagination.currentPage', 1)
            ->where('tasksPagination.perPage', 50)
            ->where('tasksPagination.total', 56)
            ->where('tasksPagination.lastPage', 2)
            ->where('tasksPagination.hasMorePages', true)
            ->where('tasksPagination.nextPageUrl', fn (?string $url): bool => $url !== null && str_contains($url, 'page=2')));

    $this->get($this->tenantUrl($tenant, "/project-portal/{$token}?page=2"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tasks', 6)
            ->where('tasks.0.id', $orderedIds[50])
            ->where('tasks.5.id', $orderedIds[55])
            ->where('tasksPagination.currentPage', 2)
            ->where('tasksPagination.hasMorePages', false)
            ->where('tasksPagination.nextPageUrl', null));
});

it('rejects unauthorized client portal comments without creating one', function () {
    $tenant = $this->createTenant('projects-client-portal-comments');
    $this->entitle($tenant, 'projects');
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant));

    $fixture = $this->inTenant($tenant, function () use ($owner): array {
        $contacts = app(ContactDirectory::class);
        $project = app(ProjectManager::class)->create(['name' => 'Portal comments'], $owner, $owner);
        $otherProject = app(ProjectManager::class)->create(['name' => 'Other portal'], $owner, $owner);
        $tasks = app(TaskManager::class);
        $visibleTask = $tasks->create($project, ['title' => 'Visible task', 'client_visible' => true], $owner, $owner);
        $hiddenTask = $tasks->create($project, ['title' => 'Hidden task'], $owner, $owner);
        $otherTask = $tasks->create($otherProject, ['title' => 'Other visible task', 'client_visible' => true], $owner, $owner);
        $access = app(ClientAccessManager::class);
        $disabled = $access->grant($project, $contacts->createContact(['first_name' => 'Disabled'], $owner), null, false, false, now()->addDay(), $owner);
        $active = $access->grant($project, $contacts->createContact(['first_name' => 'Active'], $owner), null, true, false, now()->addDay(), $owner);
        $expired = $access->grant($project, $contacts->createContact(['first_name' => 'Expired'], $owner), null, true, false, now()->subMinute(), $owner);
        $revoked = $access->grant($project, $contacts->createContact(['first_name' => 'Revoked'], $owner), null, true, false, now()->addDay(), $owner);
        $access->revoke($revoked['access'], $owner);
        $rotationContact = $contacts->createContact(['first_name' => 'Rotated'], $owner);
        $rotated = $access->grant($project, $rotationContact, null, true, false, now()->addDay(), $owner);
        $current = $access->grant($project, $rotationContact, null, true, false, now()->addDay(), $owner);

        return [
            'visible_task_id' => $visibleTask->id,
            'hidden_task_id' => $hiddenTask->id,
            'other_task_id' => $otherTask->id,
            'disabled_token' => $disabled['token'],
            'active_token' => $active['token'],
            'expired_token' => $expired['token'],
            'revoked_token' => $revoked['token'],
            'rotated_token' => $rotated['token'],
            'current_token' => $current['token'],
            'current_access_id' => $current['access']->id,
        ];
    });

    $attemptNumber = 0;
    $attempt = function (string $token, string $taskId, string $body) use ($tenant, &$attemptNumber) {
        $attemptNumber++;

        return $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attemptNumber])->post(
            $this->tenantUrl($tenant, "/project-portal/{$token}/tasks/{$taskId}/comments"),
            ['body' => $body],
        );
    };

    $attempt($fixture['disabled_token'], $fixture['visible_task_id'], 'Disabled')->assertNotFound();
    $attempt($fixture['active_token'], $fixture['hidden_task_id'], 'Hidden')->assertNotFound();
    $attempt($fixture['active_token'], $fixture['other_task_id'], 'Mismatched')->assertNotFound();
    $attempt($fixture['expired_token'], $fixture['visible_task_id'], 'Expired')->assertNotFound();
    $attempt($fixture['revoked_token'], $fixture['visible_task_id'], 'Revoked')->assertNotFound();
    $attempt($fixture['rotated_token'], $fixture['visible_task_id'], 'Rotated')->assertNotFound();

    expect($this->inTenant($tenant, fn (): int => TaskComment::query()->count()))->toBe(0);

    $attempt($fixture['current_token'], $fixture['visible_task_id'], 'Published comment')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $comment = $this->inTenant($tenant, fn (): TaskComment => TaskComment::query()->sole());

    expect($comment->body)->toBe('Published comment')
        ->and($comment->client_access_id)->toBe($fixture['current_access_id'])
        ->and($comment->is_internal)->toBeFalse();
});

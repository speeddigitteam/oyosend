<?php

declare(strict_types=1);

use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Actions\GoalManager;
use App\Modules\Talent\Actions\InterviewManager;
use App\Modules\Talent\Actions\ReviewCycleManager;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Widgets\TalentOverviewWidget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->talentTenant('talent-http');
    $this->hrmAt('2026-03-10 10:00:00');
});

it('answers 404 for every Talent page when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('talent-plain');
    $this->entitle($plain, 'hrm');
    $this->actingAsOwner($plain);

    foreach (['/me', '/openings', '/candidates', '/applications', '/interviews', '/checklists', '/tasks', '/probations', '/reviews', '/cycles', '/goals', '/courses', '/enrolments', '/analytics', '/settings'] as $path) {
        $this->get($this->talentUrl($plain, $path))->assertNotFound();
    }
});

it('forbids a member without the permission and lets a holder in', function () {
    [$nobody] = $this->hrmMember($this->tenant, ['talent.self.view']);
    [$recruiter] = $this->hrmMember($this->tenant, ['talent.openings.view', 'talent.openings.create']);

    $this->actingAsMember($this->tenant, $nobody)->get($this->talentUrl($this->tenant, '/openings'))->assertForbidden();
    $this->actingAsMember($this->tenant, $recruiter)->get($this->talentUrl($this->tenant, '/openings'))->assertOk();
    // Creating needs `create`, not just `view`; candidates were never granted.
    $this->actingAsMember($this->tenant, $recruiter)->get($this->talentUrl($this->tenant, '/candidates'))->assertForbidden();
    $this->actingAsMember($this->tenant, $recruiter)->post($this->talentUrl($this->tenant, '/candidates'), ['first_name' => 'X'])->assertForbidden();
});

it('runs the recruiter workflow over HTTP as the workspace owner', function () {
    $this->actingAsOwner($this->tenant);
    $panelist = $this->hire($this->tenant, ['first_name' => 'Panel']);

    $this->post($this->talentUrl($this->tenant, '/openings'), [
        'title' => 'Data Analyst', 'headcount' => 2, 'status' => 'open', 'employment_type' => 'full_time',
    ])->assertRedirect();
    $opening = $this->inTenant($this->tenant, fn () => JobOpening::query()->sole());

    $this->post($this->talentUrl($this->tenant, '/candidates'), [
        'first_name' => 'Rima', 'last_name' => 'Das', 'email' => 'RIMA@example.test', 'consent_basis' => 'applied_directly',
    ])->assertRedirect();
    $candidate = $this->inTenant($this->tenant, fn () => Candidate::query()->sole());
    expect($candidate->email)->toBe('rima@example.test');

    // A second record with the same email is refused unless confirmed.
    $this->post($this->talentUrl($this->tenant, '/candidates'), ['first_name' => 'Rima', 'email' => 'rima@example.test'])->assertSessionHasErrors('email');
    $this->getJson($this->talentUrl($this->tenant, '/candidates/lookup?email=rima@example.test'))->assertOk()->assertJsonPath('matches.0.id', $candidate->id);

    $this->post($this->talentUrl($this->tenant, "/openings/{$opening->id}/applications"), ['candidate_id' => $candidate->id])->assertRedirect();
    $application = $this->inTenant($this->tenant, fn () => Application::query()->sole());
    $screening = $this->pipelineStage($this->tenant, 'Screening');

    $this->post($this->talentUrl($this->tenant, "/applications/{$application->id}/move"), ['stage_id' => $screening])->assertRedirect();

    $this->post($this->talentUrl($this->tenant, "/applications/{$application->id}/interviews"), [
        'round_name' => 'Technical', 'mode' => 'video', 'date' => '2026-03-12', 'time' => '14:00', 'duration_minutes' => 45, 'panel' => [$panelist->id],
    ])->assertRedirect();

    $this->get($this->talentUrl($this->tenant, "/applications/{$application->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Talent/Pages/Applications/Show')
            ->where('application.stage.name', 'Screening')
            ->where('application.candidate.email', 'rima@example.test')
            ->has('history', 2)
            ->has('interviews', 1)
            ->where('interviews.0.date', '2026-03-12')
            ->where('interviews.0.time', '14:00')
            ->where('interviews.0.timezone', 'Asia/Dhaka')
            ->where('abilities.hire', true));

    $this->get($this->talentUrl($this->tenant, "/openings/{$opening->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Talent/Pages/Openings/Show')
            ->where('opening.applications', 1)
            ->has('pipeline.stages', 6)
            ->has('applications', 1));

    $this->post($this->talentUrl($this->tenant, "/applications/{$application->id}/hire"), ['start_on' => '2026-04-01'])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $application->refresh()->status->value))->toBe('hired');
});

it('limits an own-scoped recruiter to the openings and applications they are the hiring manager of', function () {
    [$userA, $staffA] = $this->hrmStaff($this->tenant, ['talent.openings.view', 'talent.applications.view', 'talent.candidates.view'], [
        'talent.openings.view' => 'own', 'talent.applications.view' => 'own', 'talent.candidates.view' => 'own',
    ], ['first_name' => 'Manager', 'last_name' => 'A']);
    $mine = $this->talentOpening($this->tenant, ['title' => 'Mine', 'hiring_manager_id' => $staffA->id]);
    $theirs = $this->talentOpening($this->tenant, ['title' => 'Theirs']);
    $application = $this->talentApplication($this->tenant, $mine);
    $hidden = $this->talentApplication($this->tenant, $theirs, $this->talentCandidate($this->tenant, ['email' => 'other@example.test']));

    $this->actingAsMember($this->tenant, $userA);

    $this->get($this->talentUrl($this->tenant, '/openings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('openings.data', 1)->where('openings.data.0.title', 'Mine'));
    $this->get($this->talentUrl($this->tenant, "/openings/{$theirs->id}"))->assertNotFound();
    $this->get($this->talentUrl($this->tenant, "/applications/{$application->id}"))->assertOk();
    $this->get($this->talentUrl($this->tenant, "/applications/{$hidden->id}"))->assertNotFound();
    // The recruiter who added the candidate to *their* opening owns the candidate; this one does not.
    $this->get($this->talentUrl($this->tenant, '/candidates'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('candidates.data', 0));
});

it('keeps candidate documents private: scoped download, attachment headers, tenant storage', function () {
    $this->actingAsOwner($this->tenant);
    $candidate = $this->talentCandidate($this->tenant);

    $this->post($this->talentUrl($this->tenant, "/candidates/{$candidate->id}/documents"), [
        'file' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'), 'type' => 'resume', 'title' => 'CV',
    ])->assertRedirect();
    // Executable or script uploads are refused.
    $this->post($this->talentUrl($this->tenant, "/candidates/{$candidate->id}/documents"), [
        'file' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php'), 'type' => 'other', 'title' => 'Nope',
    ])->assertSessionHasErrors('file');

    $document = $this->inTenant($this->tenant, fn () => CandidateDocument::query()->sole());
    $disk = Storage::disk((string) config('tenancy.filesystem.disk', 'local'));
    $url = $this->talentUrl($this->tenant, "/candidates/{$candidate->id}/documents/{$document->id}");

    $full = $this->inTenant($this->tenant, fn (): string => app(TenantStorage::class)->path($document->path));

    expect($document->path)->toStartWith("talent/candidates/{$candidate->id}/documents/")
        ->and($full)->toStartWith('tenants/'.$this->tenant->getTenantKey().'/')
        ->and($disk->exists($full))->toBeTrue();

    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="cv.pdf"');

    // A member without the candidate permission, and one outside their scope, get nothing.
    [$nobody] = $this->hrmMember($this->tenant, ['talent.openings.view']);
    [$scoped] = $this->hrmMember($this->tenant, ['talent.candidates.view'], ['talent.candidates.view' => 'own']);
    $this->actingAsMember($this->tenant, $nobody)->get($url)->assertForbidden();
    $this->actingAsMember($this->tenant, $scoped)->get($url)->assertNotFound();

    // Another workspace's owner cannot reach it by id.
    $other = $this->talentTenant('talent-http-other');
    $this->startNewBrowserSession()->actingAsOwner($other)
        ->get($this->talentUrl($other, "/candidates/{$candidate->id}/documents/{$document->id}"))->assertNotFound();

    // Removing the record removes the file.
    $this->startNewBrowserSession()->actingAsOwner($this->tenant)->delete($url)->assertRedirect();
    expect($disk->exists($full))->toBeFalse();
});

it('shows each panelist only their own scorecard until they have submitted theirs', function () {
    [$userA, $a] = $this->hrmStaff($this->tenant, ['talent.interviews.view', 'talent.interviews.feedback'], ['talent.interviews.view' => 'own'], ['first_name' => 'Anna']);
    [$userB, $b] = $this->hrmStaff($this->tenant, ['talent.interviews.view', 'talent.interviews.feedback'], ['talent.interviews.view' => 'own'], ['first_name' => 'Boris']);
    $application = $this->talentApplication($this->tenant);

    $interview = $this->inTenant($this->tenant, fn () => app(InterviewManager::class)->schedule($application, [
        'round_name' => 'Panel', 'mode' => 'in_person', 'date' => '2026-03-10', 'time' => '16:00', 'duration_minutes' => 30, 'panel' => [$a->id, $b->id],
    ], null));

    $this->hrmAt('2026-03-10 17:00:00');   // the 16:00 interview has started
    $url = $this->talentUrl($this->tenant, "/interviews/{$interview->id}");
    $card = fn (int $rating) => ['rating' => $rating, 'recommendation' => 'yes', 'strengths' => 'ok'];

    $this->actingAsMember($this->tenant, $userB)->post("{$url}/scorecard", $card(2))->assertRedirect();
    $this->startNewBrowserSession();

    $this->actingAsMember($this->tenant, $userA)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interview.scorecards', 0)->where('interview.scorecardCount', 1)->where('interview.abilities.feedback', true));

    $this->post("{$url}/scorecard", $card(5))->assertRedirect();
    $this->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interview.scorecards', 2));

    // Someone who is not on the panel cannot even open it with only the own scope.
    [$userC] = $this->hrmStaff($this->tenant, ['talent.interviews.view', 'talent.interviews.feedback'], ['talent.interviews.view' => 'own'], ['first_name' => 'Chris']);
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $userC)->get($url)->assertNotFound();
    $this->post("{$url}/scorecard", $card(3))->assertNotFound();
});

it('shows an employee only their own review and hides the manager side until calibration', function () {
    [$userE, $employee] = $this->hrmStaff($this->tenant, ['talent.reviews.view', 'talent.reviews.submit'], ['talent.reviews.view' => 'own'], ['first_name' => 'Emon', 'joined_on' => '2025-06-01']);
    [$userM, $manager] = $this->hrmStaff($this->tenant, ['talent.reviews.view', 'talent.reviews.submit', 'talent.reviews.calibrate'], ['talent.reviews.view' => 'own', 'talent.reviews.calibrate' => 'all'], ['first_name' => 'Mona', 'joined_on' => '2025-01-01']);
    $this->inTenant($this->tenant, fn () => app(EmployeeManager::class)->update($employee, ['manager_id' => $manager->id]));
    [, $peer] = $this->hrmStaff($this->tenant, ['talent.reviews.view'], ['talent.reviews.view' => 'own'], ['first_name' => 'Peer', 'joined_on' => '2025-06-01']);

    $form = $this->talentForm($this->tenant);
    $cycle = $this->talentCycle($this->tenant, $form);
    $this->inTenant($this->tenant, fn () => app(ReviewCycleManager::class)->launch($cycle, null));
    $items = $this->inTenant($this->tenant, fn () => $form->items()->orderBy('position')->pluck('id')->all());
    $review = $this->inTenant($this->tenant, fn () => Review::query()->where('employee_id', $employee->id)->sole());
    $peerReview = $this->inTenant($this->tenant, fn () => Review::query()->where('employee_id', $peer->id)->sole());
    $url = $this->talentUrl($this->tenant, "/reviews/{$review->id}");
    $ratings = fn (int $a, int $b) => ['ratings' => [$items[0] => ['rating' => $a], $items[1] => ['rating' => $b]], 'comment' => 'text'];

    // The employee sees their own, not a colleague's, and takes the self step.
    $this->actingAsMember($this->tenant, $userE)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.submitSelf', true)->where('abilities.submitManager', false));
    $this->get($this->talentUrl($this->tenant, "/reviews/{$peerReview->id}"))->assertNotFound();
    $this->get($this->talentUrl($this->tenant, '/reviews'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('reviews.data', 1));
    $this->post("{$url}/self", $ratings(4, 5))->assertRedirect();
    // They cannot write the manager's step or calibrate.
    $this->post("{$url}/manager", $ratings(5, 5))->assertNotFound();
    $this->post("{$url}/calibrate", [])->assertForbidden();

    // The reviewer reaches it through the reporting line although `own` scope would not.
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $userM)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.submitManager', true));
    $this->post("{$url}/manager", $ratings(3, 4))->assertRedirect();

    $this->startNewBrowserSession()->actingAsMember($this->tenant, $userE)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('review.status', 'pending_calibration')
            ->where('review.selfScore', 440)
            ->where('review.managerScore', null)
            ->where('review.finalScore', null)
            ->where('review.managerComment', null)
            ->has('ratings', 2));

    // Calibrate and release: now the employee sees the outcome and can acknowledge it.
    $this->inTenant($this->tenant, function () use ($cycle): void {
        app(ReviewCycleManager::class)->startCalibration($cycle, null);
    });
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $userM)->post("{$url}/calibrate", ['note' => 'Fair'])->assertRedirect();

    $this->startNewBrowserSession()->actingAsMember($this->tenant, $userE)->get($url)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('review.managerScore', 340)
            ->where('review.finalScore', 340)
            ->where('review.scoreText.final', '3.40')
            ->where('abilities.acknowledge', true)
            ->has('ratings', 4));
    $this->post("{$url}/acknowledge", ['comment' => 'Noted'])->assertRedirect();
});

it('lets an employee manage their own goals and nobody else\'s through the own scope', function () {
    [$user, $employee] = $this->hrmStaff($this->tenant, ['talent.goals.view', 'talent.goals.manage'], ['talent.goals.view' => 'own', 'talent.goals.manage' => 'own']);
    $other = $this->hire($this->tenant, ['first_name' => 'Colleague']);
    $this->actingAsMember($this->tenant, $user);

    $this->post($this->talentUrl($this->tenant, '/goals'), ['title' => 'Ship feature', 'kind' => 'kpi', 'start_value' => 0, 'target_value' => 10])->assertRedirect();
    $goal = $this->inTenant($this->tenant, fn () => Goal::query()->where('employee_id', $employee->id)->sole());

    $this->post($this->talentUrl($this->tenant, "/goals/{$goal->id}/progress"), ['value' => 4, 'note' => 'Halfway-ish'])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $goal->refresh()->current_value))->toBe(4);

    // For somebody else: outside the own scope, so it does not exist for this person.
    $this->post($this->talentUrl($this->tenant, '/goals'), ['employee_id' => $other->id, 'title' => 'Not mine', 'target_value' => 5])->assertNotFound();

    $foreign = $this->inTenant($this->tenant, fn (): Goal => app(GoalManager::class)->create(
        app(EmployeeDirectory::class)->find($other->id), ['title' => 'Theirs', 'target_value' => 3], null,
    ));
    $this->get($this->talentUrl($this->tenant, "/goals/{$foreign->id}"))->assertNotFound();
    $this->post($this->talentUrl($this->tenant, "/goals/{$foreign->id}/progress"), ['value' => 3])->assertNotFound();
});

it('lets an assignee complete their own task but not somebody else\'s', function () {
    $this->talentTemplate($this->tenant, 'onboarding', [['title' => 'Read handbook', 'assignee_role' => 'employee']]);
    [$userA, $a] = $this->hrmStaff($this->tenant, ['talent.tasks.complete', 'talent.self.view'], [], ['first_name' => 'Ayesha']);
    [$userB, $b] = $this->hrmStaff($this->tenant, ['talent.tasks.complete', 'talent.self.view'], [], ['first_name' => 'Bashir']);

    $taskA = $this->inTenant($this->tenant, fn () => ChecklistTask::query()->where('assignee_employee_id', $a->id)->sole());
    $taskB = $this->inTenant($this->tenant, fn () => ChecklistTask::query()->where('assignee_employee_id', $b->id)->sole());

    $this->actingAsMember($this->tenant, $userA)->get($this->talentUrl($this->tenant, '/tasks'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tasks.data', 1));
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$taskB->id}/complete"))->assertNotFound();
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$taskA->id}/complete"), ['note' => 'Done'])->assertRedirect();

    expect($this->inTenant($this->tenant, fn () => $taskA->refresh()->status->value))->toBe('done');
    $this->get($this->talentUrl($this->tenant, '/me'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/Talent/Pages/Me/Show')->where('hasProfile', true)->has('tasks', 0));
});

it('serves analytics and the dashboard widgets', function () {
    $this->talentApplication($this->tenant);
    $this->hire($this->tenant, ['first_name' => 'Lead']);

    $this->actingAsOwner($this->tenant)->get($this->talentUrl($this->tenant, '/analytics'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Talent/Pages/Analytics/Index')
            ->where('recruitment.applications.total', 1)
            ->where('recruitment.applications.open', 1)
            ->where('workforce.headcount', 1)
            ->has('workforce.months', 12)
            ->has('recruitment.funnel')
            ->has('training.enrolments'));

    $owner = $this->ownerOf($this->tenant);
    $this->inTenant($this->tenant, function () use ($owner): void {
        $overview = app(TalentOverviewWidget::class)($owner);
        expect($overview['openOpenings'])->toBe(1)->and($overview['activeApplications'])->toBe(1);
    });
});

it('narrows employee pickers to the record scope of the manage permission and refuses forged ids', function () {
    $dept = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Eng']));
    $other = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));
    $perms = ['talent.goals.view', 'talent.goals.manage', 'talent.enrolments.view', 'talent.enrolments.manage', 'talent.onboarding.view', 'talent.onboarding.manage'];

    [$ownUser, $ownEmp] = $this->hrmStaff($this->tenant, $perms, array_fill_keys($perms, 'own'), ['first_name' => 'Solo']);
    [$deptUser, $deptEmp, $deptMembership] = $this->hrmStaff($this->tenant, $perms, array_fill_keys($perms, 'department'), ['first_name' => 'Lead', 'department_id' => $dept->id]);
    $this->inTenant($this->tenant, fn () => $deptMembership->update(['department_id' => $dept->id]));
    $mate = $this->hire($this->tenant, ['first_name' => 'Mate', 'department_id' => $dept->id]);
    $stranger = $this->hire($this->tenant, ['first_name' => 'Stranger', 'department_id' => $other->id]);

    $ids = function ($user, string $path, string $prop): array {
        $props = $this->actingAsMember($this->tenant, $user)->get($this->talentUrl($this->tenant, $path))->assertOk()->viewData('page')['props'];

        return collect(Arr::get($props, $prop))->pluck('id')->all();
    };

    expect($ids($ownUser, '/enrolments', 'employees'))->toBe([$ownEmp->id])
        ->and($ids($ownUser, '/goals', 'form.employees'))->toBe([$ownEmp->id])
        ->and($ids($ownUser, '/checklists', 'employees'))->toBe([$ownEmp->id]);

    $deptIds = $ids($deptUser, '/goals', 'form.employees');
    expect($deptIds)->toContain($deptEmp->id, $mate->id)->not->toContain($stranger->id, $ownEmp->id);

    // Forged ids outside the scope are refused.
    $this->actingAsMember($this->tenant, $ownUser)->post($this->talentUrl($this->tenant, '/goals'), [
        'employee_id' => $stranger->id, 'title' => 'X', 'target_value' => 10, 'kind' => 'goal',
    ])->assertNotFound();
    $this->actingAsMember($this->tenant, $deptUser)->post($this->talentUrl($this->tenant, '/enrolments'), [
        'employee_id' => $stranger->id, 'course_id' => str_repeat('0', 26),
    ])->assertNotFound();
});

it('refuses interview panelists and task assignees outside the scope of the actor', function () {
    $dept = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Eng']));
    $other = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));
    $perms = ['talent.applications.view', 'talent.interviews.view', 'talent.interviews.manage'];
    [$user, , $membership] = $this->hrmStaff($this->tenant, $perms, ['talent.applications.view' => 'all', 'talent.interviews.manage' => 'department'], ['department_id' => $dept->id]);
    $this->inTenant($this->tenant, fn () => $membership->update(['department_id' => $dept->id]));
    $mate = $this->hire($this->tenant, ['first_name' => 'Mate', 'department_id' => $dept->id]);
    $stranger = $this->hire($this->tenant, ['first_name' => 'Stranger', 'department_id' => $other->id]);
    $application = $this->talentApplication($this->tenant, $this->talentOpening($this->tenant, ['department_id' => $dept->id]));
    $payload = ['round_name' => 'Tech', 'mode' => 'video', 'date' => '2026-03-12', 'time' => '14:00', 'duration_minutes' => 45];
    $url = $this->talentUrl($this->tenant, "/applications/{$application->id}/interviews");

    $this->actingAsMember($this->tenant, $user)->post($url, [...$payload, 'panel' => [$stranger->id]])->assertSessionHasErrors('panel');
    $this->actingAsMember($this->tenant, $user)->post($url, [...$payload, 'panel' => [$mate->id]])->assertRedirect();
});

it('hides candidate headline and contact details from people without candidates.view', function () {
    $candidate = $this->talentCandidate($this->tenant, ['headline' => 'Secret headline', 'phone' => '01700000000']);
    $application = $this->talentApplication($this->tenant, null, $candidate);
    [$viewer] = $this->hrmMember($this->tenant, ['talent.applications.view', 'talent.interviews.view']);

    $props = $this->actingAsMember($this->tenant, $viewer)->get($this->talentUrl($this->tenant, '/applications'))->assertOk()->viewData('page')['props'];
    $row = $props['applications']['data'][0]['candidate'];
    expect($row['name'])->toBe('Tania Islam')->and($row['headline'])->toBeNull()
        ->and($row['email'])->toBeNull()->and($row['phone'])->toBeNull();

    [$full] = $this->hrmMember($this->tenant, ['talent.applications.view', 'talent.candidates.view']);
    $props = $this->actingAsMember($this->tenant, $full)->get($this->talentUrl($this->tenant, "/applications/{$application->id}"))->assertOk()->viewData('page')['props'];
    expect($props['application']['candidate']['headline'])->toBe('Secret headline');
});

it('gates candidate details, notes, documents and search by the candidate record scope', function () {
    $candidate = $this->talentCandidate($this->tenant, ['email' => 'scoped.secret@example.test', 'headline' => 'Hidden headline']);
    $application = $this->talentApplication($this->tenant, null, $candidate);
    $this->inTenant($this->tenant, fn () => app(CandidateManager::class)->addNote($candidate, $application, 'Private recruiter note', null));
    $this->inTenant($this->tenant, fn () => CandidateDocument::query()->create([
        'candidate_id' => $candidate->id, 'application_id' => $application->id, 'type' => 'resume', 'title' => 'Hidden CV',
        'path' => 'x/cv.pdf', 'original_name' => 'cv.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
    ]));

    // May list every application but sees candidate details only of candidates they own.
    [$narrow] = $this->hrmMember($this->tenant, ['talent.applications.view', 'talent.candidates.view'], [
        'talent.applications.view' => 'all', 'talent.candidates.view' => 'own',
    ]);
    [$full] = $this->hrmMember($this->tenant, ['talent.applications.view', 'talent.candidates.view'], [
        'talent.applications.view' => 'all', 'talent.candidates.view' => 'all',
    ]);

    $this->actingAsMember($this->tenant, $narrow);
    $row = $this->get($this->talentUrl($this->tenant, '/applications'))->assertOk()->viewData('page')['props']['applications']['data'][0]['candidate'];
    expect($row['email'])->toBeNull()->and($row['headline'])->toBeNull();

    // The search cannot be used to find a candidate outside the candidate scope.
    $this->get($this->talentUrl($this->tenant, '/applications?q=scoped.secret'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('applications.data', 0));

    $props = $this->get($this->talentUrl($this->tenant, "/applications/{$application->id}"))->assertOk()->viewData('page')['props'];
    expect($props['application']['candidate']['email'])->toBeNull()
        ->and($props['notes'])->toBe([])
        ->and($props['documents'])->toBe([])
        ->and($props['abilities']['viewCandidate'])->toBeFalse();

    $this->actingAsMember($this->tenant, $full);
    $this->get($this->talentUrl($this->tenant, '/applications?q=scoped.secret'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('applications.data', 1)->where('applications.data.0.candidate.email', 'scoped.secret@example.test'));
    $props = $this->get($this->talentUrl($this->tenant, "/applications/{$application->id}"))->assertOk()->viewData('page')['props'];
    expect($props['notes'])->toHaveCount(1)->and($props['documents'])->toHaveCount(1);
});

it('does not let people complete, fail or cancel their own enrolment, or decide their own probation, without workspace-wide scope', function () {
    [$user, $employee] = $this->hrmStaff($this->tenant, ['talent.self.view', 'talent.enrolments.view', 'talent.enrolments.manage', 'talent.probation.view', 'talent.probation.manage'], [
        'talent.enrolments.manage' => 'department', 'talent.probation.manage' => 'department',
    ], ['first_name' => 'Selfie', 'status' => 'probation', 'joined_on' => '2026-02-01', 'probation_ends_on' => '2026-04-15']);
    [$allUser] = $this->hrmMember($this->tenant, ['talent.enrolments.view', 'talent.enrolments.manage', 'talent.probation.view', 'talent.probation.manage'], [
        'talent.enrolments.manage' => 'all', 'talent.probation.manage' => 'all',
    ]);

    $enrolment = $this->inTenant($this->tenant, function () use ($employee): Enrolment {
        $training = app(TrainingManager::class);
        $course = $training->saveCourse(null, ['title' => 'Safety', 'validity_months' => 12]);

        return $training->enrol($course, app(EmployeeDirectory::class)->find($employee->id), null, null);
    });
    $probation = $this->inTenant($this->tenant, fn () => Probation::query()->where('employee_id', $employee->id)->sole());

    $this->actingAsMember($this->tenant, $user);
    $this->post($this->talentUrl($this->tenant, "/enrolments/{$enrolment->id}/complete"), ['completed_on' => '2026-03-09'])->assertForbidden();
    $this->post($this->talentUrl($this->tenant, "/enrolments/{$enrolment->id}/fail"))->assertForbidden();
    $this->post($this->talentUrl($this->tenant, "/enrolments/{$enrolment->id}/cancel"))->assertForbidden();
    $this->post($this->talentUrl($this->tenant, "/probations/{$probation->id}/decisions"), ['decision' => 'confirm'])->assertForbidden();
    $this->get($this->talentUrl($this->tenant, "/probations/{$probation->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.decide', false));

    expect($this->inTenant($this->tenant, fn () => $enrolment->refresh()->status->value))->toBe('enrolled')
        ->and($this->inTenant($this->tenant, fn () => $probation->refresh()->status->value))->toBe('in_progress');

    // Someone with workspace-wide scope may.
    $this->actingAsMember($this->tenant, $allUser);
    $this->post($this->talentUrl($this->tenant, "/enrolments/{$enrolment->id}/cancel"))->assertRedirect();
    $this->post($this->talentUrl($this->tenant, "/probations/{$probation->id}/decisions"), ['decision' => 'confirm'])->assertRedirect();
});

it('does not let an assignee complete or reopen a task a manager skipped', function () {
    $this->talentTemplate($this->tenant, 'onboarding', [['title' => 'Read handbook', 'assignee_role' => 'employee']]);
    [$user, $employee] = $this->hrmStaff($this->tenant, ['talent.tasks.complete', 'talent.self.view'], [], ['first_name' => 'Ayesha']);
    $task = $this->inTenant($this->tenant, fn () => ChecklistTask::query()->where('assignee_employee_id', $employee->id)->sole());

    $this->actingAsOwner($this->tenant);
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$task->id}/skip"), ['note' => 'Not needed'])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $task->refresh()->status->value))->toBe('skipped');

    $this->actingAsMember($this->tenant, $user);
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$task->id}/complete"))->assertNotFound();
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$task->id}/reopen"))->assertNotFound();
    expect($this->inTenant($this->tenant, fn () => $task->refresh()->status->value))->toBe('skipped');

    // The manager may still reopen it.
    $this->actingAsOwner($this->tenant);
    $this->post($this->talentUrl($this->tenant, "/checklist-tasks/{$task->id}/reopen"))->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $task->refresh()->status->value))->toBe('pending');
});

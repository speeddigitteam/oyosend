<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\RoleManager;
use App\Core\Audit\Models\AuditLog;
use App\Core\Organization\Models\Department;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Reports\Models\SavedReport;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Reporting\FixtureReport;
use Tests\Support\InteractsWithReports;

uses(InteractsWithReports::class);

beforeEach(function () {
    FixtureReport::reset();
    $this->app->tag([FixtureReport::class], ReportProvider::TAG);
    $this->reportsAt('2026-03-09 10:00');
    $this->tenant = $this->reportsTenant('rep-saved');
    $this->url = fn (string $path = '') => $this->reportsUrl($this->tenant, $path);
    [$this->authorUser, $this->author] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.reports.export', 'reports.saved.manage']);
    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Weekly fixture',
        'provider_key' => 'reports.fixture',
        'columns' => ['name', 'count'],
        'filters' => ['kind' => 'a'],
        'range_mode' => 'last_days',
        'range_days' => 7,
        'visibility' => 'private',
        ...$overrides,
    ];
});

it('creates a saved report as the owner, validated against the provider declaration', function () {
    $response = $this->actingAsMember($this->tenant, $this->authorUser)->post(($this->url)('/saved'), ($this->payload)());

    $saved = $this->inTenant($this->tenant, fn () => SavedReport::query()->sole());
    $response->assertRedirect(($this->url)('/saved/'.$saved->id));

    expect($saved->owner_membership_id)->toBe($this->author->id)
        ->and($saved->columns)->toBe(['name', 'count'])
        ->and($saved->filters)->toBe(['kind' => 'a'])
        ->and($saved->range_days)->toBe(7);

    expect(AuditLog::query()->where('event', 'reports.saved.created')->count())->toBe(1);
});

it('refuses a saved report that the provider declaration would refuse', function () {
    $member = $this->actingAsMember($this->tenant, $this->authorUser);

    $member->post(($this->url)('/saved'), ($this->payload)(['columns' => ['secret']]))->assertSessionHasErrors('columns.0');
    $member->post(($this->url)('/saved'), ($this->payload)(['filters' => ['kind' => 'zzz']]))->assertSessionHasErrors('filters.kind');
    $member->post(($this->url)('/saved'), ($this->payload)(['filters' => ['injected' => '1']]))->assertSessionHasErrors('filters');
    $member->post(($this->url)('/saved'), ($this->payload)(['provider_key' => 'crm.pipeline']))->assertSessionHasErrors('provider_key');
    $member->post(($this->url)('/saved'), ($this->payload)(['range_days' => 367]))->assertSessionHasErrors('range_days');
    $member->post(($this->url)('/saved'), ($this->payload)(['range_mode' => 'fixed', 'range_from' => '2025-01-01', 'range_to' => '2026-03-09']))->assertSessionHasErrors('to');
    $member->post(($this->url)('/saved'), ($this->payload)(['visibility' => 'role', 'role_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))->assertSessionHasErrors('role_id');
    $member->post(($this->url)('/saved'), ($this->payload)(['visibility' => 'department', 'department_id' => null]))->assertSessionHasErrors('department_id');

    expect($this->inTenant($this->tenant, fn () => SavedReport::query()->count()))->toBe(0);
});

it('refuses a duplicate name for the same owner', function () {
    $member = $this->actingAsMember($this->tenant, $this->authorUser);
    $member->post(($this->url)('/saved'), ($this->payload)())->assertRedirect();
    $member->post(($this->url)('/saved'), ($this->payload)())->assertSessionHasErrors('name');
});

it('needs the manage permission to save, and a viewer cannot', function () {
    [$viewer] = $this->reportsMember($this->tenant, ['reports.reports.view']);

    $this->actingAsMember($this->tenant, $viewer)->post(($this->url)('/saved'), ($this->payload)())->assertForbidden();
});

it('enforces the plan limit on saved reports', function () {
    $plan = $this->createFreePlan(['key' => 'two-saved', 'modules' => ['reports'], 'limits' => ['reports.saved_reports' => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $member = $this->actingAsMember($this->tenant, $this->authorUser);

    $member->post(($this->url)('/saved'), ($this->payload)(['name' => 'One']))->assertRedirect();
    $member->post(($this->url)('/saved'), ($this->payload)(['name' => 'Two']))->assertRedirect();
    $member->post(($this->url)('/saved'), ($this->payload)(['name' => 'Three']))->assertSessionHasErrors('name');

    expect($this->inTenant($this->tenant, fn () => SavedReport::query()->count()))->toBe(2);
});

it('opens a saved report with its stored columns, filters and relative dates', function () {
    FixtureReport::$rows = [['name' => 'A', 'currency' => 'BDT', 'amount' => 1, 'count' => 9]];
    $saved = $this->savedReport($this->tenant, $this->author, [
        'columns' => ['name', 'count'], 'filters' => ['kind' => 'b'], 'range_days' => 7,
    ]);

    $this->actingAsMember($this->tenant, $this->authorUser)->get(($this->url)('/saved/'.$saved->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('saved.id', $saved->id)
            ->where('saved.isOwner', true)
            ->where('saved.canManage', true)
            ->where('values.from', '2026-03-03')
            ->where('values.to', '2026-03-09')
            ->where('values.filters.kind', 'b')
            ->where('result.columns.0.key', 'name')
            ->where('result.columns.1.key', 'count'));
});

it('keeps a private report private and shares one by role or department', function () {
    $department = $this->inTenant($this->tenant, fn () => Department::query()->create(['name' => 'Ops']));
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->ownerOf($this->tenant), 'Analysts', null, ['reports.reports.view'],
    ));
    $roleUser = $this->addMember($this->tenant, $role);
    [$outsider] = $this->reportsMember($this->tenant, ['reports.reports.view']);
    [$deptUser, $deptMembership] = $this->reportsMember($this->tenant, ['reports.reports.view']);
    $this->inTenant($this->tenant, fn () => $deptMembership->forceFill(['department_id' => $department->id])->save());
    app(AccessChecker::class)->forgetMemberships();

    $private = $this->savedReport($this->tenant, $this->author, ['name' => 'Private']);
    $byRole = $this->savedReport($this->tenant, $this->author, ['name' => 'By role', 'visibility' => 'role', 'role_id' => $role->id]);
    $byDepartment = $this->savedReport($this->tenant, $this->author, ['name' => 'By department', 'visibility' => 'department', 'department_id' => $department->id]);

    $names = fn ($user): array => collect($this->actingAsMember($this->tenant, $user)->get(($this->url)())->assertOk()
        ->viewData('page')['props']['saved'])->pluck('name')->sort()->values()->all();

    expect($names($this->authorUser))->toBe(['By department', 'By role', 'Private'])
        ->and($names($roleUser))->toBe(['By role'])
        ->and($names($deptUser))->toBe(['By department'])
        ->and($names($outsider))->toBe([]);

    $this->actingAsMember($this->tenant, $outsider)->get(($this->url)('/saved/'.$private->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $outsider)->get(($this->url)('/saved/'.$byRole->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $roleUser)->get(($this->url)('/saved/'.$byRole->id))->assertOk();
    $this->actingAsMember($this->tenant, $roleUser)->get(($this->url)('/saved/'.$byDepartment->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $deptUser)->get(($this->url)('/saved/'.$byDepartment->id))->assertOk();
});

it('lets only the owner, or a holder of manage_all, change or delete a saved report', function () {
    $saved = $this->savedReport($this->tenant, $this->author, ['visibility' => 'role']);
    [$peerUser] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.saved.manage']);
    [$adminUser] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.saved.manage', 'reports.saved.manage_all']);

    // A peer who cannot even see it gets a 404, not a hint that it exists.
    $this->actingAsMember($this->tenant, $peerUser)->put(($this->url)('/saved/'.$saved->id), ($this->payload)())->assertNotFound();
    $this->actingAsMember($this->tenant, $peerUser)->delete(($this->url)('/saved/'.$saved->id))->assertNotFound();

    $this->actingAsMember($this->tenant, $adminUser)->put(($this->url)('/saved/'.$saved->id), ($this->payload)(['name' => 'Renamed']))->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => $saved->fresh()->name))->toBe('Renamed');

    $this->actingAsMember($this->tenant, $this->authorUser)->delete(($this->url)('/saved/'.$saved->id))->assertRedirect(($this->url)());
    expect($this->inTenant($this->tenant, fn () => SavedReport::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('event', 'reports.saved.deleted')->count())->toBe(1);
});

it('does not turn a shared report into a data grant', function () {
    $this->entitle($this->tenant, 'invoicing');
    $department = $this->inTenant($this->tenant, fn () => Department::query()->create(['name' => 'Finance']));
    [$viewer, $membership] = $this->reportsMember($this->tenant, ['reports.reports.view']);
    $this->inTenant($this->tenant, fn () => $membership->forceFill(['department_id' => $department->id])->save());
    app(AccessChecker::class)->forgetMemberships();

    // The owner shares a receivables report with the department; the viewer lacks invoicing.reports.view.
    [, $ownerMembership] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.saved.manage', 'invoicing.reports.view']);
    $saved = $this->savedReport($this->tenant, $ownerMembership, [
        'provider_key' => 'invoicing.receivables_ageing', 'visibility' => 'department', 'department_id' => $department->id,
    ]);

    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/saved/'.$saved->id))->assertNotFound();
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/saved/'.$saved->id.'/export'))->assertForbidden();

    $row = collect($this->actingAsMember($this->tenant, $viewer)->get(($this->url)())->viewData('page')['props']['saved'])->firstWhere('id', $saved->id);
    expect($row['available'])->toBeFalse()->and($row['providerLabel'])->toBeNull();
});

it('exports a saved report with the audit entry pointing at it', function () {
    FixtureReport::$rows = [['name' => 'A', 'currency' => 'BDT', 'amount' => 700, 'count' => 1]];
    $saved = $this->savedReport($this->tenant, $this->author, ['columns' => ['name', 'amount']]);

    $response = $this->actingAsMember($this->tenant, $this->authorUser)->get(($this->url)('/saved/'.$saved->id.'/export'));

    $response->assertOk();
    expect($response->getContent())->toContain('"Name","Currency","Amount (minor units)"')
        ->and($response->getContent())->toContain('"A","BDT","700"');

    $entry = AuditLog::query()->where('event', 'reports.report.exported')->sole();
    expect($entry->subject_id)->toBe($saved->id);
});

it('drops sharing when the role it was shared with is deleted', function () {
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->ownerOf($this->tenant), 'Temporary', null, ['reports.reports.view'],
    ));
    $saved = $this->savedReport($this->tenant, $this->author, ['visibility' => 'role', 'role_id' => $role->id]);

    $this->inTenant($this->tenant, fn () => app(RoleManager::class)->delete($role));

    $fresh = $this->inTenant($this->tenant, fn () => $saved->fresh());
    expect($fresh->role_id)->toBeNull()->and($fresh->owner_membership_id)->toBe($this->author->id);
});

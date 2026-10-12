<?php

declare(strict_types=1);

use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Tenancy\TenantContext;
use App\Modules\Reports\Models\SavedReport;
use Tests\Support\Fixtures\Reporting\FixtureReport;
use Tests\Support\InteractsWithReports;

uses(InteractsWithReports::class);

beforeEach(function () {
    FixtureReport::reset();
    $this->app->tag([FixtureReport::class], ReportProvider::TAG);
});

it('isolates saved reports between tenants', function () {
    $this->assertTenantIsolated(SavedReport::class, function () {
        $owner = $this->membershipOf(app(TenantContext::class)->require(), $this->ownerOf(app(TenantContext::class)->require()));

        return SavedReport::query()->create([
            'owner_membership_id' => $owner->id,
            'name' => 'Isolated '.bin2hex(random_bytes(3)),
            'provider_key' => 'reports.fixture',
            'range_mode' => 'last_days',
            'range_days' => 30,
            'visibility' => 'private',
        ]);
    });
});

it('answers 404 for another workspace\'s saved report on every route that takes an id', function () {
    $home = $this->reportsTenant('rep-home');
    $owner = $this->membershipOf($home, $this->ownerOf($home));
    $saved = $this->savedReport($home, $owner);
    $payload = [
        'name' => 'Edited', 'provider_key' => 'reports.fixture', 'range_mode' => 'last_days', 'range_days' => 7, 'visibility' => 'private',
    ];

    $this->assertNotFoundFromOtherTenant('GET', '/reports/saved/'.$saved->id, $home);
    $this->assertNotFoundFromOtherTenant('GET', '/reports/saved/'.$saved->id.'/export', $home);
    $this->assertNotFoundFromOtherTenant('PUT', '/reports/saved/'.$saved->id, $home, $payload);
    // A destructive verb: it really deletes in the control request, so it goes last.
    $this->assertNotFoundFromOtherTenant('DELETE', '/reports/saved/'.$saved->id, $home);
});

it('never lets another workspace see this workspace\'s saved reports in its catalogue', function () {
    $home = $this->reportsTenant('rep-home-2');
    $other = $this->reportsTenant('rep-other-2');
    $this->savedReport($home, $this->membershipOf($home, $this->ownerOf($home)), ['name' => 'Home only']);

    $names = collect($this->actingAsOwner($other)->get($this->reportsUrl($other))->assertOk()->viewData('page')['props']['saved'])->pluck('name');

    expect($names->all())->toBe([]);
});

<?php

declare(strict_types=1);

use App\Core\Contacts\Models\Contact;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithReports;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithReports::class, InteractsWithHrm::class, InteractsWithSupport::class);

beforeEach(function () {
    $this->reportsAt('2026-03-09 10:00');
    $this->tenant = $this->reportsTenant('rep-scope', 'crm', 'projects', 'hrm', 'support');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->rows = function ($user, string $key, string $query = ''): array {
        $page = $this->actingAsMember($this->tenant, $user)
            ->get($this->reportsUrl($this->tenant, '/run/'.$key.($query === '' ? '' : '?'.$query)))
            ->assertOk()->viewData('page')['props'];

        return $page['result']['rows'];
    };
});

it('narrows the CRM pipeline to the deals the viewer may see', function () {
    [$ownUser, $own] = $this->reportsMember($this->tenant, ['reports.reports.view', 'crm.deals.view'], ['crm.deals.view' => 'own']);
    [$allUser] = $this->reportsMember($this->tenant, ['reports.reports.view', 'crm.deals.view'], ['crm.deals.view' => 'all']);

    $this->inTenant($this->tenant, function () use ($own): void {
        $pipeline = app(PipelineManager::class)->create('Sales', [
            ['name' => 'Discovery', 'probability' => 20],
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ]);
        $deals = app(DealManager::class);
        $contact = Contact::factory()->create(['first_name' => 'Rima']);
        $deals->create(['title' => 'Owner deal', 'contact_id' => $contact->id, 'currency' => 'BDT', 'amount' => 100000], $pipeline, $pipeline->stages[0], $this->owner, $this->owner);
        $deals->create(['title' => 'Member deal', 'contact_id' => $contact->id, 'currency' => 'BDT', 'amount' => 50000], $pipeline, $pipeline->stages[0], $own, $this->owner);
    });

    $mine = ($this->rows)($ownUser, 'crm.pipeline');
    $everything = ($this->rows)($allUser, 'crm.pipeline');

    expect($mine)->toHaveCount(1)
        ->and($mine[0])->toMatchArray(['pipeline' => 'Sales', 'stage' => 'Discovery', 'currency' => 'BDT', 'deals' => 1, 'amount' => 50000, 'weighted_amount' => 10000])
        ->and($everything)->toHaveCount(1)
        ->and($everything[0])->toMatchArray(['deals' => 2, 'amount' => 150000, 'weighted_amount' => 30000]);

    // The export carries exactly what the screen shows.
    [$exporter] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.reports.export', 'crm.deals.view'], ['crm.deals.view' => 'own']);
    $csv = $this->actingAsMember($this->tenant, $exporter)->get($this->reportsUrl($this->tenant, '/run/crm.pipeline/export'))->assertOk()->getContent();
    expect($csv)->not->toContain('"150000"')->and(substr_count($csv, "\r\n"))->toBe(1);
});

it('reports leads by source and narrows to the leads the viewer may see', function () {
    [$ownUser, $own] = $this->reportsMember($this->tenant, ['reports.reports.view', 'crm.leads.view'], ['crm.leads.view' => 'own']);

    $this->inTenant($this->tenant, function () use ($own): void {
        $source = app(PipelineManager::class)->createSource(['name' => 'Website']);
        $leads = app(LeadManager::class);
        $contact = Contact::factory()->create(['first_name' => 'Rima']);
        $leads->create(['title' => 'Mine 1', 'contact_id' => $contact->id, 'source_id' => $source->id], $own);
        $leads->create(['title' => 'Mine 2', 'contact_id' => $contact->id], $own);
        $leads->create(['title' => 'Theirs', 'contact_id' => $contact->id, 'source_id' => $source->id], $this->owner);
    });

    $rows = collect(($this->rows)($ownUser, 'crm.lead_sources'))->keyBy('source');

    expect($rows)->toHaveCount(2)
        ->and($rows['Website']['leads'])->toBe(1)
        ->and($rows['No source']['leads'])->toBe(1)
        ->and($rows['Website']['conversion_bps'])->toBe(0);
});

it('narrows timesheets to the entries the viewer may see and keeps financials of the project private', function () {
    [$ownUser, $own] = $this->reportsMember($this->tenant, ['reports.reports.view', 'projects.time.view', 'projects.projects.view'], [
        'projects.time.view' => 'own', 'projects.projects.view' => 'all',
    ]);

    $this->inTenant($this->tenant, function () use ($own): void {
        $project = app(ProjectManager::class)->create(['name' => 'Website', 'budget_amount' => 900000], $this->owner, $this->owner);

        foreach ([[$own, 60], [$this->owner, 120]] as [$member, $minutes]) {
            $entry = new TimeEntry;
            $entry->forceFill([
                'project_id' => $project->id, 'membership_id' => $member->id, 'owner_id' => $member->id,
                'worked_on' => '2026-03-05', 'minutes' => $minutes, 'is_billable' => true, 'currency' => 'BDT',
                'billable_rate_amount' => 6000, 'cost_rate_amount' => 3000, 'status' => EntryStatus::Approved,
            ])->save();
        }
    });

    $time = ($this->rows)($ownUser, 'projects.timesheets');
    expect($time)->toHaveCount(1)->and($time[0]['minutes'])->toBe(60)->and($time[0]['project'])->toBe('Website');

    // The viewer may see the project but does not hold the financials permission: money is null, not zero.
    $performance = ($this->rows)($ownUser, 'projects.performance', 'columns[]=name&columns[]=budget_amount&columns[]=spent_amount');
    expect($performance)->toHaveCount(1)
        ->and($performance[0]['name'])->toBe('Website')
        ->and($performance[0]['budget_amount'])->toBeNull()
        ->and($performance[0]['spent_amount'])->toBeNull();
});

it('narrows the attendance summary to the employees the viewer may see', function () {
    [$ownUser, $employee] = $this->hrmStaff($this->tenant, ['reports.reports.view', 'hrm.attendance.view'], ['hrm.attendance.view' => 'own'], ['first_name' => 'Mina']);
    $other = $this->hire($this->tenant, ['first_name' => 'Rafi']);

    $this->inTenant($this->tenant, function () use ($employee, $other): void {
        foreach ([$employee, $other] as $person) {
            $day = new AttendanceDay;
            $day->forceFill([
                'employee_id' => $person->id, 'work_date' => '2026-03-04', 'status' => 'present',
                'scheduled_minutes' => 480, 'worked_minutes' => 470, 'is_closed' => true,
                'owner_id' => $person->owner_id, 'department_id' => $person->department_id, 'branch_id' => $person->branch_id,
            ])->save();
        }
    });

    $rows = ($this->rows)($ownUser, 'hrm.attendance');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toContain('Mina')
        ->and($rows[0]['present'])->toBe(1)
        ->and($rows[0]['worked_minutes'])->toBe(470);

    // An HR viewer with the all scope sees both.
    [$hrUser] = $this->reportsMember($this->tenant, ['reports.reports.view', 'hrm.attendance.view'], ['hrm.attendance.view' => 'all']);
    expect(($this->rows)($hrUser, 'hrm.attendance'))->toHaveCount(2);
});

it('reports ticket volume per day for a holder of the support reports permission', function () {
    $this->supportTicket($this->tenant, ['subject' => 'One']);
    $this->supportTicket($this->tenant, ['subject' => 'Two']);
    [$user] = $this->reportsMember($this->tenant, ['reports.reports.view', 'support.reports.view']);

    $rows = collect(($this->rows)($user, 'support.volume', 'from=2026-03-08&to=2026-03-09'))->keyBy('date');

    expect($rows['2026-03-09']['created'])->toBe(2)
        ->and($rows['2026-03-08']['created'])->toBe(0);
});

it('runs every shipped provider and exports it, on an empty workspace, without error', function () {
    $this->entitle($this->tenant, 'invoicing', 'payroll', 'talent', 'social', 'automation');
    $owner = $this->actingAsOwner($this->tenant);
    $keys = [
        'crm.pipeline', 'crm.lead_sources', 'crm.sales_conversion', 'projects.performance', 'projects.timesheets',
        'invoicing.receivables_ageing', 'invoicing.revenue', 'hrm.headcount', 'hrm.attendance', 'hrm.leave',
        'payroll.run_totals', 'talent.recruitment_funnel', 'support.volume', 'support.sla', 'support.csat',
        'social.publishing', 'social.engagement', 'automation.run_outcomes',
    ];

    foreach ($keys as $key) {
        $page = $owner->get($this->reportsUrl($this->tenant, '/run/'.$key))->assertOk()->viewData('page')['props'];

        expect($page['report']['key'])->toBe($key)
            ->and($page['result']['rows'])->toBeArray()
            ->and($page['result']['truncated'])->toBeFalse();

        // Every declared column is selectable and the export is a well-formed CSV.
        $all = collect($page['report']['columns'])->pluck('key')->map(fn (string $column): string => 'columns[]='.$column)->implode('&');
        // The export is throttled (20 a minute); this loop makes more.
        Cache::flush();
        $csv = $owner->get($this->reportsUrl($this->tenant, '/run/'.$key.'/export?'.$all))->assertOk()->getContent();
        expect($csv)->toStartWith("\xEF\xBB\xBF\"");
    }
});

it('reports the numbers of an invoice-free workspace as zero rows rather than failing', function () {
    $this->entitle($this->tenant, 'invoicing');
    $page = $this->actingAsOwner($this->tenant)->get($this->reportsUrl($this->tenant, '/run/invoicing.receivables_ageing'))->assertOk()->viewData('page')['props'];

    expect($page['result']['rows'])->toBe([])->and($page['report']['usesDateRange'])->toBeFalse();
});

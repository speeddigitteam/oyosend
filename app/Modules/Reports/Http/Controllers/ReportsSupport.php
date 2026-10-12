<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Models\Role;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Department;
use App\Core\Reporting\ReportRegistry;
use App\Modules\Reports\Actions\ReportOutput;
use App\Modules\Reports\Actions\SavedReportAccess;
use App\Modules\Reports\Actions\SavedReportManager;
use App\Modules\Reports\Http\ReportPresenter;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Shared controller helpers: the signed-in person and the one page a report is shown on. */
final class ReportsSupport
{
    public const string VIEW = 'reports.reports.view';

    public const string EXPORT = 'reports.reports.export';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly ReportRegistry $registry,
        private readonly SavedReportAccess $savedAccess,
        private readonly UsageLimits $limits,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * One saved report as the viewer sees it.
     *
     * @return array<string, mixed>
     */
    public function savedRow(User $viewer, SavedReport $saved): array
    {
        $saved->loadMissing('owner.user:id,name');
        $providers = $this->registry->all();
        $available = $this->registry->find($saved->provider_key, $viewer) !== null;
        $membership = $this->access->membership($viewer);

        return ReportPresenter::saved(
            $saved,
            isOwner: $membership !== null && $saved->owner_membership_id === $membership->id,
            canManage: $this->savedAccess->canManage($viewer, $saved),
            available: $available,
            providerLabel: isset($providers[$saved->provider_key]) && $available ? $providers[$saved->provider_key]->definition()->label : null,
        );
    }

    /**
     * The run page for one output.
     *
     * @param  array<string, mixed>  $values  What the form shows: from, to, columns, filters.
     */
    public function page(User $viewer, ReportOutput $output, array $values, int $page, ?SavedReport $saved): Response
    {
        $canManage = $this->access->allows($viewer, SavedReportAccess::MANAGE);
        $limit = $this->limits->limit(SavedReportManager::LIMIT);

        return Inertia::render('Modules/Reports/Pages/Run', [
            'report' => ReportPresenter::definition($output->definition),
            'values' => $values,
            'result' => ReportPresenter::result($output, $page),
            'saved' => $saved === null ? null : $this->savedRow($viewer, $saved),
            'bounds' => ReportPresenter::bounds(),
            'canExport' => $this->access->allows($viewer, self::EXPORT),
            'canSave' => $canManage,
            'share' => ! $canManage ? ['roles' => [], 'departments' => []] : [
                'roles' => Role::query()->forCurrentTenant()->orderBy('name')->get(['id', 'name'])
                    ->map(static fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all(),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                    ->map(static fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            ],
            'savedLimit' => ['used' => $this->limits->used(SavedReportManager::LIMIT), 'max' => $limit],
        ]);
    }
}

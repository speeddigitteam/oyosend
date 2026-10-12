<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Organization\Models\Department;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\SalaryManager;
use App\Modules\Payroll\Enums\PaymentMethod;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\AccountRequest;
use App\Modules\Payroll\Http\Requests\SalaryRevisionRequest;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salaries: per-employee immutable revisions and the payment account. Record scope applies through
 * the profile's copied placement (`own`, `department`, `branch`, `all`).
 */
final class SalaryController
{
    public function __construct(
        private readonly SalaryManager $salaries,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate(['department_id' => ['nullable', 'string', 'size:26']]);
        $today = $this->support->today()->toDateString();

        $query = $this->support->records()->constrain(Profile::query(), $actor, PayrollPermissions::SALARIES_VIEW)
            ->when(($filters['department_id'] ?? '') !== '', fn ($inner) => $inner->where('department_id', $filters['department_id']))
            ->orderBy('id');

        $profiles = $query->paginate(25)->withQueryString();
        $employees = $this->support->employees($profiles->getCollection()->pluck('employee_id')->all());
        $assignments = SalaryAssignment::query()
            ->with('structure:id,name')
            ->whereIn('profile_id', $profiles->getCollection()->pluck('id')->all())
            ->orderBy('effective_from')
            ->get()
            ->groupBy('profile_id');

        $profiles->through(function (Profile $profile) use ($employees, $assignments, $today): array {
            $rows = $assignments->get($profile->id, collect());
            $current = $rows->filter(fn (SalaryAssignment $row): bool => $row->effective_from->toDateString() <= $today)->last();
            $upcoming = $rows->first(fn (SalaryAssignment $row): bool => $row->effective_from->toDateString() > $today);

            return [
                'id' => $profile->id,
                'employee' => PayrollPresenter::employee($employees[$profile->employee_id] ?? null),
                'current' => $current === null ? null : PayrollPresenter::assignment($current),
                'upcoming' => $upcoming === null ? null : PayrollPresenter::assignment($upcoming),
                'hasAccount' => $profile->account_number !== null && $profile->account_number !== '',
            ];
        });

        $canManage = $this->access->allows($actor, PayrollPermissions::SALARIES_MANAGE);
        $assigned = SalaryAssignment::query()->distinct()->pluck('employee_id')->flip();

        return Inertia::render('Modules/Payroll/Pages/Salaries/Index', [
            'profiles' => $profiles,
            'filters' => ['department_id' => $filters['department_id'] ?? ''],
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(static fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            'unassigned' => $canManage
                ? array_slice(array_filter(
                    $this->support->employeeOptions($actor, PayrollPermissions::SALARIES_MANAGE),
                    static fn (array $employee): bool => ! isset($assigned[$employee['id']]),
                ), 0, 100)
                : [],
            'structures' => $canManage ? $this->structureOptions() : [],
            'currency' => $this->support->currency(),
            'canManage' => $canManage,
        ]);
    }

    public function show(Request $request, Profile $profile): Response
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::SALARIES_VIEW, $profile);
        $canManage = $this->access->allows($actor, PayrollPermissions::SALARIES_MANAGE)
            && $this->support->records()->allows($actor, PayrollPermissions::SALARIES_MANAGE, $profile);

        return Inertia::render('Modules/Payroll/Pages/Salaries/Show', [
            'profile' => ['id' => $profile->id, 'employeeId' => $profile->employee_id],
            'employee' => PayrollPresenter::employee($this->support->directory()->find($profile->employee_id)),
            'assignments' => $profile->assignments()->with('structure:id,name')->get()
                ->map(static fn (SalaryAssignment $assignment): array => PayrollPresenter::assignment($assignment))->all(),
            'account' => PayrollPresenter::account($profile),
            'structures' => $canManage ? $this->structureOptions() : [],
            'paymentMethods' => PaymentMethod::values(),
            'currency' => $this->support->currency(),
            'today' => $this->support->today()->toDateString(),
            'canManage' => $canManage,
        ]);
    }

    /** A first salary for an employee (creates the payroll profile). */
    public function store(SalaryRevisionRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();

        if (! isset($input['employee_id'])) {
            throw ValidationException::withMessages(['employee_id' => __('Choose an employee.')]);
        }

        $employee = $this->support->employee($actor, (string) $input['employee_id'], PayrollPermissions::SALARIES_MANAGE);
        $structure = SalaryStructure::query()->findOrFail((string) $input['structure_id']);
        $this->salaries->assign($employee, $structure, (int) $input['basic_amount'], (string) $input['effective_from'], $input['reason'] ?? null, $this->support->membership($actor));
        Toast::success(__('Salary saved.'));

        $profile = Profile::query()->where('employee_id', $employee->id)->firstOrFail();

        return redirect()->route('payroll.salaries.show', $profile);
    }

    /** A new revision for an employee who already has a salary. */
    public function revise(SalaryRevisionRequest $request, Profile $profile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::SALARIES_MANAGE, $profile);
        $employee = $this->support->employee($actor, $profile->employee_id, PayrollPermissions::SALARIES_MANAGE);
        $input = $request->validated();
        $structure = SalaryStructure::query()->findOrFail((string) $input['structure_id']);
        $this->salaries->assign($employee, $structure, (int) $input['basic_amount'], (string) $input['effective_from'], $input['reason'] ?? null, $this->support->membership($actor));
        Toast::success(__('Salary revision recorded.'));

        return back();
    }

    public function updateAccount(AccountRequest $request, Profile $profile): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::SALARIES_MANAGE, $profile);
        $this->salaries->updateAccount($profile, $request->validated());
        Toast::success(__('Payment account saved.'));

        return back();
    }

    /** @return list<array{id: string, name: string}> */
    private function structureOptions(): array
    {
        return SalaryStructure::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(static fn (SalaryStructure $structure): array => ['id' => $structure->id, 'name' => $structure->name])->all();
    }
}

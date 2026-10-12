<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\TerminationType;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\EmployeeRequest;
use App\Modules\Hrm\Http\Requests\StatusRequest;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\HrmPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class EmployeeController
{
    /** Fields only someone with `all` reach may change on their own record. */
    private const array SELF_LOCKED = [
        'designation_id', 'department_id', 'branch_id', 'manager_id', 'employment_type', 'probation_ends_on',
        'cost_rate_amount', 'cost_currency', 'membership_id',
    ];

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly EmployeeManager $employees,
        private readonly EmployeeResolver $resolver,
        private readonly HrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'employment_type' => ['nullable', 'string', 'max:20'],
        ]);

        $query = $this->records->constrain(Employee::query(), $actor, HrmPermissions::EMPLOYEES_VIEW)
            ->with(['department:id,name', 'branch:id,name', 'designation:id,name', 'manager:id,first_name,last_name']);

        if (($filters['q'] ?? '') !== '') {
            $like = Like::contains($filters['q']);
            $query->where(fn ($match) => $match
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('employee_number', 'like', $like)
                ->orWhere('work_email', 'like', $like));
        }

        foreach (['status', 'department_id', 'branch_id', 'manager_id', 'employment_type'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        $employees = $query->orderBy('employee_number')->paginate(25)->withQueryString()
            ->through(static fn (Employee $employee): array => HrmPresenter::employeeRow($employee));

        return Inertia::render('Modules/Hrm/Pages/Employees/Index', [
            'employees' => $employees,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
                'department_id' => $filters['department_id'] ?? '',
                'branch_id' => $filters['branch_id'] ?? '',
                'manager_id' => $filters['manager_id'] ?? '',
                'employment_type' => $filters['employment_type'] ?? '',
            ],
            'statuses' => array_map(static fn (EmployeeStatus $status): string => $status->value, EmployeeStatus::cases()),
            'employmentTypes' => array_map(static fn (EmploymentType $type): string => $type->value, EmploymentType::cases()),
            'departments' => $this->support->employeeFormOptions()['departments'],
            'branches' => $this->support->employeeFormOptions()['branches'],
            'canCreate' => $this->access->allows($actor, HrmPermissions::EMPLOYEES_CREATE),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Hrm/Pages/Employees/Create', [
            'form' => $this->support->employeeFormOptions(null, $actor),
            'defaults' => ['joinedOn' => $this->support->today()->toDateString(), 'status' => EmployeeStatus::Probation->value, 'employmentType' => EmploymentType::FullTime->value],
        ]);
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $employee = $this->employees->create($request->validated(), $this->support->membership($actor));
        Toast::success(__('Employee created.'));

        return redirect()->route('hrm.employees.show', $employee);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_VIEW, $employee);
        $employee->load(['department:id,name', 'branch:id,name', 'designation:id,name', 'manager:id,first_name,last_name', 'owner.user:id,name,email']);

        $isSelf = $this->resolver->isSelf($actor, $employee);
        $personal = $isSelf || $this->records->allows($actor, HrmPermissions::PERSONAL_VIEW, $employee);
        $canManage = $this->records->allows($actor, HrmPermissions::EMPLOYEES_MANAGE, $employee);
        $canLifecycle = ! $isSelf && $this->records->allows($actor, HrmPermissions::EMPLOYEES_LIFECYCLE, $employee);
        $costVisible = $this->records->allows($actor, HrmPermissions::PERSONAL_VIEW, $employee);

        $detail = [
            ...HrmPresenter::employeeRow($employee),
            'probationEndsOn' => $employee->probation_ends_on?->toDateString(),
            'confirmedOn' => $employee->confirmed_on?->toDateString(),
            'statusChangedOn' => $employee->status_changed_on?->toDateString(),
            'statusReason' => $personal ? $employee->status_reason : null,
            'lastWorkingOn' => $employee->last_working_on?->toDateString(),
            'terminationType' => $employee->termination_type?->value,
            'terminationReason' => $personal ? $employee->termination_reason : null,
            'membership' => $employee->owner === null ? null : [
                'id' => $employee->owner->id,
                'name' => $employee->owner->user->name,
                'email' => $employee->owner->user->email,
            ],
            'notes' => $canManage ? $employee->notes : null,
            'costRateAmount' => $costVisible ? $employee->cost_rate_amount : null,
            'costCurrency' => $costVisible ? $employee->cost_currency : null,
            'personal' => $personal ? [
                'personalEmail' => $employee->personal_email,
                'dateOfBirth' => $employee->date_of_birth?->toDateString(),
                'gender' => $employee->gender?->value,
                'nationalId' => $employee->national_id,
                'address' => $employee->address,
                'city' => $employee->city,
                'country' => $employee->country,
            ] : null,
        ];

        $reports = $this->records->constrain(Employee::query(), $actor, HrmPermissions::EMPLOYEES_VIEW)
            ->where('manager_id', $employee->id)->where('status', '!=', EmployeeStatus::Terminated)
            ->orderBy('first_name')->get(['id', 'employee_number', 'first_name', 'last_name'])
            ->map(static fn (Employee $report): array => ['id' => $report->id, 'number' => $report->employee_number, 'name' => $report->fullName()])->all();

        return Inertia::render('Modules/Hrm/Pages/Employees/Show', [
            'employee' => $detail,
            'reports' => $reports,
            'history' => $employee->history()->orderByDesc('effective_on')->orderByDesc('created_at')->limit(100)->get()
                ->map(static fn ($entry): array => HrmPresenter::history($entry, $costVisible, $personal))->all(),
            'emergencyContacts' => $personal
                ? $employee->emergencyContacts()->orderByDesc('is_primary')->orderBy('name')->get()->map(static fn ($contact): array => HrmPresenter::emergencyContact($contact))->all()
                : [],
            'documents' => $personal
                ? $employee->documents()->orderByDesc('created_at')->get()->map(static fn ($document): array => HrmPresenter::document($document))->all()
                : [],
            'assets' => $employee->assets()->orderByRaw('returned_on IS NOT NULL')->orderByDesc('assigned_on')->get()
                ->map(static fn ($asset): array => HrmPresenter::asset($asset, $personal))->all(),
            'abilities' => [
                'edit' => $canManage,
                'editEmployment' => $canManage && (! $isSelf || $this->access->scopeFor($actor, HrmPermissions::EMPLOYEES_MANAGE) === AccessScope::All),
                'changeStatus' => $canLifecycle,
                'viewPersonal' => $personal,
                'manageRecords' => $canManage,
                'isSelf' => $isSelf,
            ],
            'statusOptions' => $canLifecycle
                ? array_map(static fn (EmployeeStatus $status): string => $status->value, $employee->status->transitions())
                : [],
            'terminationTypes' => array_map(static fn (TerminationType $type): string => $type->value, TerminationType::cases()),
            'form' => $canManage ? $this->support->employeeFormOptions($employee, $actor) : null,
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_MANAGE, $employee);
        $input = $request->validated();
        $scope = $this->access->scopeFor($actor, HrmPermissions::EMPLOYEES_MANAGE);

        // Nobody widens their own record unless they hold the permission for everyone.
        if ($this->resolver->isSelf($actor, $employee) && $scope !== AccessScope::All) {
            $input = array_diff_key($input, array_flip(self::SELF_LOCKED));
        }

        // Linking an account decides who owns the record: only a workspace-wide manager may.
        if (array_key_exists('membership_id', $input) && $input['membership_id'] !== $employee->owner_id && $scope !== AccessScope::All) {
            unset($input['membership_id']);
        }

        $this->employees->update($employee, $input, $this->support->membership($actor));
        Toast::success(__('Employee saved.'));

        return back();
    }

    public function status(StatusRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, HrmPermissions::EMPLOYEES_LIFECYCLE, $employee);
        $this->denySelf($actor, $employee);
        $input = $request->validated();
        $this->employees->changeStatus($employee, EmployeeStatus::from($input['status']), $input, $this->support->membership($actor));
        Toast::success(__('Employment status updated.'));

        return back();
    }

    private function denySelf(User $actor, Employee $employee): void
    {
        abort_if($this->resolver->isSelf($actor, $employee), 403);
    }
}

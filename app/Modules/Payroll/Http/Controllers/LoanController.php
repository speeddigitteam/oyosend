<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\LoanManager;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Enums\LoanKind;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\DecisionRequest;
use App\Modules\Payroll\Http\Requests\LoanRequest;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Loans and salary advances with installment schedules deducted by payroll runs. */
final class LoanController
{
    public function __construct(
        private readonly LoanManager $loans,
        private readonly PayrollSettings $settings,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', LoanStatus::values())],
            'employee_id' => ['nullable', 'string', 'size:26'],
        ]);

        $page = $this->support->records()->constrain(Loan::query(), $actor, PayrollPermissions::LOANS_VIEW)
            ->when(($filters['status'] ?? '') !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['employee_id'] ?? '') !== '', fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $employees = $this->support->employees($page->getCollection()->pluck('employee_id')->all());
        $canManage = $this->access->allows($actor, PayrollPermissions::LOANS_MANAGE);

        return Inertia::render('Modules/Payroll/Pages/Loans/Index', [
            'loans' => $page->through(static fn (Loan $loan): array => [
                ...PayrollPresenter::loan($loan),
                'employee' => PayrollPresenter::employee($employees[$loan->employee_id] ?? null),
            ]),
            'filters' => ['status' => $filters['status'] ?? '', 'employee_id' => $filters['employee_id'] ?? ''],
            'statuses' => LoanStatus::values(),
            'kinds' => LoanKind::values(),
            'employees' => $canManage ? $this->support->employeeOptions($actor, PayrollPermissions::LOANS_MANAGE) : [],
            'currency' => $this->support->currency(),
            'today' => $this->support->today()->toDateString(),
            'canManage' => $canManage,
        ]);
    }

    public function show(Request $request, Loan $loan): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $this->support->records()->authorize($actor, PayrollPermissions::LOANS_VIEW, $loan);
        $loan->load('installments');
        $employee = $this->support->directory()->find($loan->employee_id);
        $separate = $this->settings->current()->require_separate_approver;
        $pending = $loan->status === LoanStatus::Pending;

        return Inertia::render('Modules/Payroll/Pages/Loans/Show', [
            'loan' => PayrollPresenter::loan($loan),
            'employee' => PayrollPresenter::employee($employee),
            'installments' => $loan->installments->map(static fn (LoanInstallment $installment): array => PayrollPresenter::installment($installment))->all(),
            'outstandingAmount' => (int) $loan->installments->where('status', 'pending')->sum('amount'),
            'abilities' => [
                'approve' => $pending && $this->access->allows($actor, PayrollPermissions::LOANS_APPROVE)
                    && ! ($separate && $loan->requested_by_id === $membership->id),
                'cancel' => in_array($loan->status, [LoanStatus::Pending, LoanStatus::Active], true)
                    && $this->access->allows($actor, PayrollPermissions::LOANS_MANAGE)
                    && $this->support->records()->allows($actor, PayrollPermissions::LOANS_MANAGE, $loan),
            ],
        ]);
    }

    public function store(LoanRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $employee = $this->support->employee($actor, (string) $input['employee_id'], PayrollPermissions::LOANS_MANAGE);
        $loan = $this->loans->request($employee, $input, $this->support->membership($actor));
        Toast::success(__('Loan requested. It needs approval before it is deducted.'));

        return redirect()->route('payroll.loans.show', $loan);
    }

    public function decision(DecisionRequest $request, Loan $loan): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::LOANS_VIEW, $loan);
        $input = $request->validated();
        $this->loans->decide($loan, $input['decision'] === 'approve', $input['note'] ?? null, $this->support->membership($actor));
        Toast::success($input['decision'] === 'approve' ? __('Loan approved.') : __('Loan rejected.'));

        return back();
    }

    public function cancel(Request $request, Loan $loan): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::LOANS_MANAGE, $loan);
        $this->loans->cancel($loan);
        Toast::success(__('Loan cancelled.'));

        return back();
    }
}

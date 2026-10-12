<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\RunNoteRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Payroll runs: prepare, review, submit, approve (a different member), discard, reverse. */
final class RunController
{
    public function __construct(
        private readonly RunManager $runs,
        private readonly PayrollSettings $settings,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', RunStatus::values())],
            'kind' => ['nullable', 'string', 'in:'.implode(',', RunKind::values())],
            'period_id' => ['nullable', 'string', 'size:26'],
        ]);

        $runs = Run::query()->with('period:id,name')
            ->when(($filters['status'] ?? '') !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['kind'] ?? '') !== '', fn ($query) => $query->where('kind', $filters['kind']))
            ->when(($filters['period_id'] ?? '') !== '', fn ($query) => $query->where('period_id', $filters['period_id']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Modules/Payroll/Pages/Runs/Index', [
            'runs' => $runs->through(static fn (Run $run): array => PayrollPresenter::run($run)),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'kind' => $filters['kind'] ?? '',
                'period_id' => $filters['period_id'] ?? '',
            ],
            'statuses' => RunStatus::values(),
            'kinds' => RunKind::values(),
            'preparablePeriods' => $this->access->allows($actor, PayrollPermissions::RUNS_PREPARE)
                ? Period::query()->whereIn('status', [PeriodStatus::Open, PeriodStatus::Processing])->orderByDesc('starts_on')->get()
                    ->map(static fn (Period $period): array => PayrollPresenter::period($period))->all()
                : [],
            'canPrepare' => $this->access->allows($actor, PayrollPermissions::RUNS_PREPARE),
        ]);
    }

    public function show(Request $request, Run $run): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $run->load(['period', 'preparedBy.user:id,name', 'approvedBy.user:id,name']);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', PaymentStatus::values())],
        ]);

        $payslips = Payslip::query()->where('run_id', $run->id)
            ->when(($filters['q'] ?? '') !== '', function ($query) use ($filters): void {
                $like = Like::contains($filters['q']);
                $query->where(fn ($match) => $match->where('employee_name', 'like', $like)->orWhere('employee_number', 'like', $like)->orWhere('number', 'like', $like));
            })
            ->when(($filters['payment_status'] ?? '') !== '', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->orderBy('employee_name')->orderBy('id')
            ->paginate(25)->withQueryString();

        $separate = $this->settings->current()->require_separate_approver;
        $isMaker = $membership->id === $run->prepared_by_id || $membership->id === $run->submitted_by_id;
        $reversed = Run::query()->where('reverses_run_id', $run->id)->exists();
        $reversalApproved = $run->hasApprovedReversal();
        $original = $run->reverses_run_id !== null ? Run::query()->find($run->reverses_run_id) : null;
        $can = fn (string $permission): bool => $this->access->allows($actor, $permission);
        $editable = $run->kind !== RunKind::Reversal;

        return Inertia::render('Modules/Payroll/Pages/Runs/Show', [
            'run' => [
                ...PayrollPresenter::run($run),
                'preparedByName' => $run->preparedBy?->user->name,
                'approvedByName' => $run->approvedBy?->user->name,
                'errors' => $run->errors ?? [],
                'warnings' => $run->warnings ?? [],
                'reversesRunNumber' => $original?->number,
                'isReversed' => $reversed,
            ],
            'period' => PayrollPresenter::period($run->period),
            'payslips' => $payslips->through(static fn (Payslip $payslip): array => PayrollPresenter::payslipRow($payslip)),
            'filters' => ['q' => $filters['q'] ?? '', 'payment_status' => $filters['payment_status'] ?? ''],
            'paymentStatuses' => PaymentStatus::values(),
            'approval' => [
                'requiresSeparateApprover' => $separate,
                'youPreparedOrSubmitted' => $isMaker,
            ],
            'abilities' => [
                'recalculate' => $can(PayrollPermissions::RUNS_PREPARE) && $editable && in_array($run->status, [RunStatus::Draft, RunStatus::Submitted], true),
                'submit' => $can(PayrollPermissions::RUNS_PREPARE) && $editable && $run->status === RunStatus::Draft && ($run->errors ?? []) === [] && $run->employee_count > 0,
                'sendBack' => $can(PayrollPermissions::RUNS_APPROVE) && $editable && $run->status === RunStatus::Submitted,
                'approve' => $can(PayrollPermissions::RUNS_APPROVE) && $run->status === RunStatus::Submitted && ! ($separate && $isMaker),
                'discard' => $can(PayrollPermissions::RUNS_PREPARE) && ($run->status === RunStatus::Draft || ($run->kind === RunKind::Reversal && $run->status === RunStatus::Submitted)),
                'reverse' => $can(PayrollPermissions::RUNS_REVERSE) && $run->status === RunStatus::Approved && $editable && ! $reversed,
                'recordPayments' => $can(PayrollPermissions::PAYMENTS_RECORD) && $run->status === RunStatus::Approved && $run->kind !== RunKind::Reversal && ! $reversalApproved,
                'exportPaymentSheet' => $can(PayrollPermissions::PAYMENTS_EXPORT) && $run->status === RunStatus::Approved && $editable && ! $reversalApproved,
            ],
        ]);
    }

    public function store(Request $request, Period $period): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $run = $this->runs->prepare($period, $this->support->membership($actor));
        Toast::success(__('Payroll run calculated.'));

        return redirect()->route('payroll.runs.show', $run);
    }

    public function storeAdjustment(Request $request, Period $period): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $run = $this->runs->prepareAdjustment($period, $this->support->membership($actor));
        Toast::success(__('Adjustment run calculated.'));

        return redirect()->route('payroll.runs.show', $run);
    }

    public function recalculate(Request $request, Run $run): RedirectResponse
    {
        $this->runs->recalculate($run, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Payroll run recalculated.'));

        return back();
    }

    public function submit(Request $request, Run $run): RedirectResponse
    {
        $this->runs->submit($run, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Payroll run submitted for approval.'));

        return back();
    }

    public function sendBack(RunNoteRequest $request, Run $run): RedirectResponse
    {
        $this->runs->sendBack($run, $this->support->membership($this->support->actor($request)), (string) $request->validated('note'));
        Toast::success(__('Payroll run sent back.'));

        return back();
    }

    public function approve(Request $request, Run $run): RedirectResponse
    {
        $this->runs->approve($run, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Payroll run approved and locked.'));

        return back();
    }

    public function destroy(Request $request, Run $run): RedirectResponse
    {
        $period = $run->period_id;
        $this->runs->discard($run, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Payroll run discarded.'));

        return redirect()->route('payroll.periods.show', $period);
    }

    public function reverse(RunNoteRequest $request, Run $run): RedirectResponse
    {
        $reversal = $this->runs->reverse($run, $this->support->membership($this->support->actor($request)), (string) $request->validated('note'));
        Toast::success(__('Reversal run created. A different member must approve it.'));

        return redirect()->route('payroll.runs.show', $reversal);
    }
}

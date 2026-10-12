<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\Payroll\Actions\PayslipDocument;
use App\Modules\Payroll\Enums\PaymentMethod;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payslips. Approved payslips are visible within the viewer's `payroll.payslips.view` scope; an
 * employee with the `own` scope reaches exactly their own. Draft payslips are reviewed on the run
 * page by people who hold `payroll.runs.view`.
 */
final class PayslipController
{
    public function __construct(
        private readonly PayslipDocument $documents,
        private readonly AuditLogger $audit,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'period_id' => ['nullable', 'string', 'size:26'],
            'payment_status' => ['nullable', 'string', 'in:'.implode(',', PaymentStatus::values())],
        ]);

        $query = $this->support->records()->constrain(Payslip::query()->whereNotNull('locked_at'), $actor, PayrollPermissions::PAYSLIPS_VIEW)
            ->with('period:id,name')
            ->when(($filters['q'] ?? '') !== '', function ($inner) use ($filters): void {
                $like = Like::contains($filters['q']);
                $inner->where(fn ($match) => $match->where('employee_name', 'like', $like)->orWhere('employee_number', 'like', $like)->orWhere('number', 'like', $like));
            })
            ->when(($filters['period_id'] ?? '') !== '', fn ($inner) => $inner->where('period_id', $filters['period_id']))
            ->when(($filters['payment_status'] ?? '') !== '', fn ($inner) => $inner->where('payment_status', $filters['payment_status']))
            ->orderByDesc('created_at')->orderByDesc('id');

        return Inertia::render('Modules/Payroll/Pages/Payslips/Index', [
            'payslips' => $query->paginate(25)->withQueryString()
                ->through(static fn (Payslip $payslip): array => PayrollPresenter::payslipRow($payslip)),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'period_id' => $filters['period_id'] ?? '',
                'payment_status' => $filters['payment_status'] ?? '',
            ],
            'periods' => Period::query()->orderByDesc('starts_on')->limit(60)->get(['id', 'name'])
                ->map(static fn (Period $period): array => ['id' => $period->id, 'name' => $period->name])->all(),
            'paymentStatuses' => PaymentStatus::values(),
        ]);
    }

    public function show(Request $request, Payslip $payslip): Response
    {
        $actor = $this->support->actor($request);
        $this->authorizeView($actor, $payslip);
        $payslip->load(['lines', 'payments', 'period', 'run:id,kind,number,status']);

        return Inertia::render('Modules/Payroll/Pages/Payslips/Show', [
            'payslip' => [
                ...PayrollPresenter::payslipRow($payslip),
                'runNumber' => $payslip->run->number,
                'runKind' => $payslip->run->kind->value,
                'reversesPayslipId' => $payslip->reverses_payslip_id,
                'payableDays' => $payslip->payable_days,
                'periodDays' => $payslip->period_days,
                'taxableAmount' => $payslip->taxable_amount,
                'structureName' => $payslip->structure_name,
                'attendance' => $payslip->attendance,
                'joinedOn' => $payslip->joined_on->toDateString(),
                'employmentType' => $payslip->employment_type,
            ],
            'period' => PayrollPresenter::period($payslip->period),
            'lines' => $payslip->lines->map(static fn (PayslipLine $line): array => PayrollPresenter::payslipLine($line))->all(),
            'payments' => $payslip->payments->map(static fn (Payment $payment): array => PayrollPresenter::payment($payment))->all(),
            'paymentMethods' => PaymentMethod::values(),
            'abilities' => [
                'recordPayment' => $payslip->isLocked() && $payslip->payment_status !== PaymentStatus::Paid
                    && $this->access->allows($actor, PayrollPermissions::PAYMENTS_RECORD),
            ],
        ]);
    }

    /**
     * The print-optimised document. Open it in its own tab (the app sends X-Frame-Options: DENY, so
     * it cannot be framed) and use the browser's print dialog to save a PDF.
     */
    public function document(Request $request, Payslip $payslip): HttpResponse
    {
        $actor = $this->support->actor($request);
        $this->authorizeView($actor, $payslip);

        $response = response($this->documents->html($payslip))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');

        if ($request->boolean('download')) {
            $response->header('Content-Disposition', 'attachment; filename="payslip-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payslip->number ?? $payslip->id)).'.html"');
            $this->audit->record('payroll.payslip.downloaded', $payslip, ['employee_id' => $payslip->employee_id], $actor);
        }

        return $response;
    }

    private function authorizeView(User $actor, Payslip $payslip): void
    {
        $this->support->records()->authorize($actor, PayrollPermissions::PAYSLIPS_VIEW, $payslip);
        abort_unless($payslip->isLocked() || $this->access->allows($actor, PayrollPermissions::RUNS_VIEW), 404);
    }
}

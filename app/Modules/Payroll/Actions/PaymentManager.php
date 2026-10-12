<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Payroll\Enums\PaymentMethod;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Events\PayslipPaid;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the payment status of payslips. Payroll never moves money itself: an operator pays outside
 * (bank sheet, mobile wallet, cash) and records the outcome here.
 *
 * Every decision carries a client-supplied idempotency key, unique per workspace: replaying a request
 * returns the first recording and changes nothing. A paid payslip is final; a failed one may be
 * recorded as paid later. The full net amount is recorded (partial payment is not supported).
 */
final class PaymentManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PayrollSettings $settings,
    ) {}

    /**
     * @param  array{status: string, method?: string|null, reference?: string|null, paid_on?: string|null, note?: string|null}  $input
     */
    public function record(Payslip $payslip, array $input, string $idempotencyKey, ?TenantMembership $actor = null): Payment
    {
        $key = trim($idempotencyKey);

        if (strlen($key) < 8 || strlen($key) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => __('An idempotency key of 8 to 120 characters is required.')]);
        }

        $status = PaymentStatus::tryFrom($input['status']);

        if ($status === null || $status === PaymentStatus::Unpaid) {
            throw ValidationException::withMessages(['status' => __('Choose paid or failed.')]);
        }

        try {
            return DB::transaction(fn (): Payment => $this->recordInTransaction($payslip, $status, $input, $key, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same key committed first: its record is the answer.
            $existing = Payment::query()->where('idempotency_key', $key)->first();

            if ($existing !== null && $existing->payslip_id === $payslip->id) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Record every unpaid payslip of an approved run with the same decision.
     * Each payslip gets the key `{key}:{payslip id}`, so the whole call is idempotent.
     *
     * @param  array{status: string, method?: string|null, reference?: string|null, paid_on?: string|null, note?: string|null}  $input
     * @return int How many payslips were recorded (replays count as zero)
     */
    public function recordRun(Run $run, array $input, string $keyBase, ?TenantMembership $actor = null): int
    {
        if (strlen($keyBase) < 8 || strlen($keyBase) > 90) {
            throw ValidationException::withMessages(['idempotency_key' => __('An idempotency key of 8 to 90 characters is required.')]);
        }

        $count = 0;
        $payslips = Payslip::query()->where('run_id', $run->id)->where('is_reversal', false)
            ->where('payment_status', '!=', PaymentStatus::Paid)->orderBy('id')->get();

        foreach ($payslips as $payslip) {
            $before = Payment::query()->where('idempotency_key', $keyBase.':'.$payslip->id)->exists();
            $this->record($payslip, $input, $keyBase.':'.$payslip->id, $actor);
            $count += $before ? 0 : 1;
        }

        return $count;
    }

    /** The single rule for paying or exporting a run: approved, not a reversal and not itself reversed. */
    public static function isPayable(Run $run): bool
    {
        return $run->status === RunStatus::Approved && $run->kind !== RunKind::Reversal && ! $run->hasApprovedReversal();
    }

    /** @param array<string, mixed> $input */
    private function recordInTransaction(Payslip $payslip, PaymentStatus $status, array $input, string $key, ?TenantMembership $actor): Payment
    {
        $existing = Payment::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            if ($existing->payslip_id !== $payslip->id) {
                throw ValidationException::withMessages(['idempotency_key' => __('This idempotency key was already used for another payslip.')]);
            }

            return $existing;
        }

        /** @var Payslip $locked */
        $locked = Payslip::query()->whereKey($payslip->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isLocked()) {
            throw ValidationException::withMessages(['payslip' => __('Only payslips of an approved run can be paid.')]);
        }

        if ($locked->is_reversal) {
            throw ValidationException::withMessages(['payslip' => __('A reversal payslip cannot be paid.')]);
        }

        $run = Run::query()->whereKey($locked->run_id)->first();

        if ($run === null || $run->kind === RunKind::Reversal || $run->hasApprovedReversal()) {
            throw ValidationException::withMessages(['payslip' => __('This run has been reversed; its payslips can no longer be paid.')]);
        }

        if ($locked->payment_status === PaymentStatus::Paid) {
            throw ValidationException::withMessages(['payslip' => __('This payslip is already marked as paid.')]);
        }

        $method = null;
        $paidOn = null;

        if ($status === PaymentStatus::Paid) {
            $method = PaymentMethod::tryFrom((string) ($input['method'] ?? ''));
            $paidOn = (string) ($input['paid_on'] ?? '');

            if ($method === null) {
                throw ValidationException::withMessages(['method' => __('Choose how it was paid.')]);
            }

            if ($paidOn === '' || strtotime($paidOn) === false) {
                throw ValidationException::withMessages(['paid_on' => __('Enter the payment date.')]);
            }

            if ($paidOn > $this->settings->today()->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => __('The payment date cannot be in the future.')]);
            }
        }

        $reference = isset($input['reference']) && $input['reference'] !== '' ? (string) $input['reference'] : null;

        $payment = Payment::query()->create([
            'payslip_id' => $locked->id,
            'status' => $status,
            'method' => $method,
            'reference' => $reference,
            'paid_on' => $paidOn,
            'amount' => $locked->net_amount,
            'currency' => $locked->currency,
            'idempotency_key' => $key,
            'note' => $input['note'] ?? null,
            'actor_id' => $actor?->id,
        ]);

        $locked->forceFill([
            'payment_status' => $status,
            'payment_method' => $method,
            'payment_reference' => $reference,
            'paid_on' => $paidOn,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ])->save();

        $this->audit->record('payroll.payment.recorded', $locked, [
            'status' => $status->value,
            'method' => $method?->value,
            'amount' => $locked->net_amount,
            'payment_id' => $payment->id,
        ], subjectLabel: $locked->employee_name);

        if ($status === PaymentStatus::Paid) {
            $this->completePeriod($locked);

            PayslipPaid::dispatch([
                'payslip_id' => $locked->id,
                'payslip_number' => $locked->number,
                'run_id' => $locked->run_id,
                'period_id' => $locked->period_id,
                'employee_id' => $locked->employee_id,
                'membership_id' => $locked->owner_id,
                'net_amount' => $locked->net_amount,
                'currency' => $locked->currency,
                'method' => $method->value,
                'paid_on' => $paidOn,
                'is_reversal' => $locked->is_reversal,
            ]);
        }

        return $payment;
    }

    /** When the last payslip of a period's regular run is paid, the period becomes `paid`. */
    private function completePeriod(Payslip $payslip): void
    {
        $run = Run::query()->whereKey($payslip->run_id)->first();

        if ($run === null || $run->kind !== RunKind::Regular) {
            return;
        }

        $open = Payslip::query()->where('run_id', $run->id)->where('is_reversal', false)
            ->where('payment_status', '!=', PaymentStatus::Paid)->exists();

        if ($open) {
            return;
        }

        $period = Period::query()->whereKey($run->period_id)->lockForUpdate()->first();

        if ($period !== null && $period->status === PeriodStatus::Approved) {
            $period->update(['status' => PeriodStatus::Paid]);
        }
    }
}

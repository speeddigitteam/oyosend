<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

use Carbon\CarbonImmutable;

/**
 * Computes one employee's payslip for a period. It is pure: the same input always yields the same
 * output, it reads no database and no clock, and every figure is an integer minor unit.
 *
 * The order of the pay line groups (also the order printed): basic, recurring earning components,
 * overtime, one-off earnings, reimbursements; then deduction components, unpaid leave, absence,
 * one-off deductions, rule-set deductions and loan installments; employer contributions last.
 *
 * Rules, all documented in docs/PAYROLL.md:
 *  - joiners and leavers are prorated: `payable_days / period_days` of basic and of fixed components,
 *    percentage components are taken from the prorated basic;
 *  - a day of unpaid time is worth `base / divisor` where the base is the full-period basic or gross
 *    (settings) and the divisor the period's calendar days or a fixed count (settings);
 *  - leave and absence deductions share one budget: they never exceed the prorated recurring earnings;
 *  - overtime is `basic x overtime_minutes x multiplier / (divisor x work_day_minutes)`;
 *  - every ratio is rounded half-up once (see {@see PayMath});
 *  - loan installments are deducted only when they fit in what is left of the net pay, else deferred.
 */
final class PayCalculator
{
    public function __construct(private readonly RuleSetEngines $engines) {}

    public function calculate(EmployeeInput $in, string $from, string $to, CalcSettings $settings): PayslipDraft
    {
        $periodDays = (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) + 1;
        $payable = $this->payableDays($in, $from, $to, $periodDays);
        $warnings = [];
        $lines = [];
        $sort = 0;
        $add = static function (string $kind, string $code, string $name, string $source, ?string $sourceId, int $amount, bool $taxable, ?array $detail = null) use (&$lines, &$sort): void {
            $lines[] = [
                'kind' => $kind, 'code' => $code, 'name' => $name, 'source' => $source, 'source_id' => $sourceId,
                'amount' => $amount, 'is_taxable' => $taxable, 'detail' => $detail, 'sort_order' => $sort++,
            ];
        };

        if ($payable < $periodDays) {
            $warnings[] = ['code' => 'partial_period', 'payable_days' => $payable, 'period_days' => $periodDays];
        }

        $basicFull = $in->basicAmount;
        $basic = PayMath::scale($basicFull, $payable, $periodDays);
        $add('earning', 'basic', 'Basic salary', 'system', null, $basic, true);

        // Recurring components: full-period values (for the day rate) and prorated values (paid).
        $earningFull = 0;
        $earningPaid = 0;
        $taxable = $basic;
        $deductionLines = [];

        foreach ($in->components as $component) {
            $fixed = $component['calc'] === 'fixed';
            $full = $fixed ? (int) $component['amount'] : PayMath::scale($basicFull, (int) $component['rate_bps'], PayMath::BPS);
            $paid = $fixed
                ? PayMath::scale((int) $component['amount'], $payable, $periodDays)
                : PayMath::scale($basic, (int) $component['rate_bps'], PayMath::BPS);

            if ($component['kind'] === 'earning') {
                $earningFull += $full;
                $earningPaid += $paid;
                $taxable += $component['taxable'] ? $paid : 0;
                $add('earning', $component['code'], $component['name'], 'component', $component['id'], $paid, $component['taxable']);
            } else {
                $deductionLines[] = ['code' => $component['code'], 'name' => $component['name'], 'source' => 'component', 'id' => $component['id'], 'amount' => $paid];
            }
        }

        $divisor = $settings->daysBasis === 'fixed_days' ? max(1, $settings->fixedDays) : $periodDays;
        $dayBase = $settings->dailyRateBase === 'basic' ? $basicFull : $basicFull + $earningFull;
        $budget = $basic + $earningPaid;

        // Overtime.
        $overtimeMinutes = (int) ($in->attendance['overtime_minutes'] ?? 0);

        if ($settings->maxOvertimeMinutes !== null && $overtimeMinutes > $settings->maxOvertimeMinutes) {
            $warnings[] = ['code' => 'overtime_capped', 'minutes' => $overtimeMinutes, 'cap' => $settings->maxOvertimeMinutes];
            $overtimeMinutes = $settings->maxOvertimeMinutes;
        }

        $overtime = $overtimeMinutes > 0 && $settings->overtimeMultiplierBps > 0
            ? PayMath::scale($basicFull, $overtimeMinutes * $settings->overtimeMultiplierBps, $divisor * max(1, $settings->workDayMinutes) * PayMath::BPS)
            : 0;

        if ($overtime > 0) {
            $taxable += $settings->overtimeTaxable ? $overtime : 0;
            $add('earning', 'overtime', 'Overtime', 'system', null, $overtime, $settings->overtimeTaxable, [
                'minutes' => $overtimeMinutes, 'multiplier_bps' => $settings->overtimeMultiplierBps,
            ]);
        }

        // One-off items.
        $itemIds = [];
        $reimbursement = 0;
        $itemEarnings = 0;
        $itemDeductions = [];

        foreach ($in->items as $item) {
            $amount = $item['basic_bps'] !== null
                ? PayMath::scale($basicFull, (int) $item['basic_bps'], PayMath::BPS)
                : (int) $item['amount'];

            if ($amount < 1) {
                continue;
            }

            $itemIds[] = $item['id'];

            if ($item['type'] === 'deduction') {
                $itemDeductions[] = ['code' => 'deduction', 'name' => $item['name'], 'source' => 'item', 'id' => $item['id'], 'amount' => $amount];
            } elseif ($item['type'] === 'reimbursement') {
                $reimbursement += $amount;
                $add('reimbursement', 'reimbursement', $item['name'], 'item', $item['id'], $amount, false);
            } else {
                $itemEarnings += $amount;
                $taxable += $item['taxable'] ? $amount : 0;
                $add('earning', $item['type'], $item['name'], 'item', $item['id'], $amount, $item['taxable']);
            }
        }

        $gross = $basic + $earningPaid + $overtime + $itemEarnings;

        // Recurring deduction components.
        foreach ($deductionLines as $line) {
            $add('deduction', $line['code'], $line['name'], $line['source'], $line['id'], $line['amount'], false);
        }

        // Unpaid leave and absence share the budget of recurring earnings.
        $unpaidUnits = 0;

        foreach ($in->leave as $entry) {
            $unpaidUnits += $entry['is_paid'] ? 0 : $entry['units'];
        }

        $absenceUnits = (int) ($in->attendance['days_absent'] ?? 0) * PayMath::UNITS_PER_DAY + (int) ($in->attendance['days_half'] ?? 0);
        $unpaidDeduction = 0;

        foreach ([
            ['unpaid_leave', 'Unpaid leave', $settings->deductUnpaidLeave ? $unpaidUnits : 0],
            ['absence', 'Absence', $settings->deductAbsence ? $absenceUnits : 0],
        ] as [$code, $name, $units]) {
            if ($units < 1) {
                continue;
            }

            $wanted = PayMath::scale($dayBase, $units, PayMath::UNITS_PER_DAY * $divisor);
            $amount = min($wanted, $budget);
            $budget -= $amount;

            if ($amount > 0) {
                $unpaidDeduction += $amount;
                $add('deduction', $code, $name, 'system', null, $amount, false, ['units' => $units, 'uncapped_amount' => $wanted]);
            }
        }

        foreach ($itemDeductions as $line) {
            $add('deduction', $line['code'], $line['name'], 'item', $line['id'], $line['amount'], false);
        }

        // Configurable rule sets.
        $taxableBase = max(0, $taxable - $unpaidDeduction);
        $amounts = new RuleBasisAmounts($basic, $gross, $taxableBase, $in->currency, $from, $to);
        $employer = 0;
        $ruleDeductions = 0;
        $error = null;

        foreach ($in->rules as $rule) {
            if ($rule->employmentTypes !== null && ! in_array($in->employmentType, $rule->employmentTypes, true)) {
                continue;
            }

            if (! $this->engines->has($rule->engine)) {
                $error = 'unknown_rule_engine';

                continue;
            }

            $outcome = $this->engines->get($rule->engine)->evaluate($rule, $amounts);

            if ($outcome->amount < 1) {
                continue;
            }

            if ($rule->payer === 'employer') {
                $employer = PayMath::sum([$employer, $outcome->amount]);
                $add('employer', $rule->code, $rule->name, 'rule', $rule->id, $outcome->amount, false, $outcome->detail);
            } else {
                $ruleDeductions = PayMath::sum([$ruleDeductions, $outcome->amount]);
                $add('deduction', $rule->code, $rule->name, 'rule', $rule->id, $outcome->amount, false, $outcome->detail);
            }
        }

        // Exact integer sums: a total outside the supported range throws RangeException instead of becoming a float.
        $deductions = PayMath::sum([
            ...array_column($deductionLines, 'amount'), $unpaidDeduction, ...array_column($itemDeductions, 'amount'), $ruleDeductions,
        ]);
        $remaining = PayMath::sum([$gross, $reimbursement]) - $deductions;

        if ($remaining < 0 && $error === null) {
            $error = 'negative_net';
        }

        // Loan installments: due first, only while they fit in what is left.
        $installmentIds = [];
        $installments = $in->installments;
        usort($installments, static fn (array $a, array $b): int => [$a['due_on'], $a['sequence'], $a['id']] <=> [$b['due_on'], $b['sequence'], $b['id']]);

        foreach ($installments as $installment) {
            if ($error !== null || $installment['amount'] > $remaining) {
                $warnings[] = ['code' => 'installment_deferred', 'installment_id' => $installment['id'], 'loan_id' => $installment['loan_id'], 'amount' => $installment['amount']];

                continue;
            }

            $remaining -= $installment['amount'];
            $deductions += $installment['amount'];
            $installmentIds[] = $installment['id'];
            $add('deduction', 'loan', $installment['label'], 'loan', $installment['id'], $installment['amount'], false, [
                'loan_id' => $installment['loan_id'], 'sequence' => $installment['sequence'],
            ]);
        }

        $openDays = (int) ($in->attendance['open_days'] ?? 0);

        if ($openDays > 0) {
            $warnings[] = ['code' => 'open_attendance_days', 'days' => $openDays];
        }

        if ((int) ($in->attendance['days_incomplete'] ?? 0) > 0) {
            $warnings[] = ['code' => 'incomplete_days', 'days' => (int) $in->attendance['days_incomplete']];
        }

        $net = max(0, $remaining);

        return new PayslipDraft(
            employeeId: $in->employeeId,
            payableDays: $payable,
            periodDays: $periodDays,
            basicAmount: $basic,
            grossAmount: $gross,
            taxableAmount: $taxableBase,
            deductionAmount: $deductions,
            reimbursementAmount: $reimbursement,
            netAmount: $net,
            employerAmount: $employer,
            lines: array_map(static function (array $line): array {
                unset($line['sort_order']);

                return $line;
            }, $lines),
            warnings: $warnings,
            itemIds: $itemIds,
            installmentIds: $installmentIds,
            attendance: ['summary' => $in->attendance, 'leave' => $in->leave, 'unpaid_units' => $unpaidUnits, 'absence_units' => $absenceUnits],
            error: $error,
        );
    }

    /** Calendar days of the period the employee was employed (joined / last working day applied). */
    private function payableDays(EmployeeInput $in, string $from, string $to, int $periodDays): int
    {
        $start = max($from, $in->joinedOn);
        $end = $in->lastWorkingOn !== null ? min($to, $in->lastWorkingOn) : $to;

        if ($start > $end) {
            return 0;
        }

        return min($periodDays, (int) CarbonImmutable::parse($start, 'UTC')->diffInDays(CarbonImmutable::parse($end, 'UTC')) + 1);
    }
}

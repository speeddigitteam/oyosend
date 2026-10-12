<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Billing\Support\Money;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Payroll\Enums\LineKind;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The printable payslip: plain, self-contained HTML (inline CSS, the workspace logo embedded as a
 * data URI) rendered server-side in the viewer's language. There is deliberately no PDF library:
 * the browser's print dialog produces the PDF.
 */
final class PayslipDocument
{
    private const int MAX_LOGO_BYTES = 1_048_576;

    private const array LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantStorage $storage,
        private readonly PayrollSettings $settings,
    ) {}

    public function html(Payslip $payslip): string
    {
        return view('payroll.payslip', $this->data($payslip))->render();
    }

    /** @return array<string, mixed> */
    public function data(Payslip $payslip): array
    {
        $payslip->loadMissing(['lines', 'run', 'period']);
        $tenant = $this->context->require();
        $currency = $payslip->currency;
        $money = static fn (int $amount): string => Money::format($amount, $currency);
        $row = fn (PayslipLine $line): array => [
            'name' => $line->source === 'system' ? __($line->name) : $line->name,
            'amount' => $money($line->amount),
            'taxable' => $line->is_taxable,
        ];
        $lines = $payslip->lines;
        $of = static fn (LineKind $kind): Collection => $lines->where('kind', $kind);

        $seller = [
            'name' => $tenant->legal_name ?? $tenant->name,
            'lines' => array_values(array_filter([
                $tenant->address, $tenant->city, $tenant->postal_code, $tenant->country,
                $tenant->tax_id !== null ? __('Tax ID').': '.$tenant->tax_id : null,
                $tenant->email, $tenant->phone,
            ], static fn (?string $line): bool => $line !== null && trim($line) !== '')),
        ];

        $totals = [
            ['label' => __('Gross pay'), 'value' => $money($payslip->gross_amount)],
            ['label' => __('Total deductions'), 'value' => '-'.$money($payslip->deduction_amount)],
        ];

        if ($payslip->reimbursement_amount > 0) {
            $totals[] = ['label' => __('Reimbursements'), 'value' => $money($payslip->reimbursement_amount)];
        }

        $totals[] = ['label' => __('Net pay'), 'value' => $money($payslip->net_amount), 'strong' => true];

        $attendance = $payslip->attendance['summary'] ?? null;
        $facts = [];

        if (is_array($attendance) && $payslip->payable_days > 0) {
            $facts[] = ['label' => __('Days paid'), 'value' => $payslip->payable_days.' / '.$payslip->period_days];
            $facts[] = ['label' => __('Days present'), 'value' => (string) ((int) ($attendance['days_present'] ?? 0) + (int) ($attendance['days_half'] ?? 0))];
            $facts[] = ['label' => __('Days absent'), 'value' => (string) (int) ($attendance['days_absent'] ?? 0)];
            $facts[] = ['label' => __('Overtime'), 'value' => intdiv((int) ($attendance['overtime_minutes'] ?? 0), 60).':'.str_pad((string) ((int) ($attendance['overtime_minutes'] ?? 0) % 60), 2, '0', STR_PAD_LEFT)];
        }

        $paid = $payslip->payment_status === PaymentStatus::Paid;

        return [
            'heading' => $payslip->is_reversal ? __('Payslip reversal') : __('Payslip'),
            'number' => $payslip->number,
            'seller' => $seller,
            'orgName' => $seller['name'],
            'logo' => $this->logo(),
            'locale' => app()->getLocale(),
            'employee' => [
                'name' => $payslip->employee_name,
                'rows' => array_values(array_filter([
                    ['label' => __('Employee number'), 'value' => $payslip->employee_number],
                    $payslip->designation_name !== null ? ['label' => __('Designation'), 'value' => $payslip->designation_name] : null,
                    $payslip->department_name !== null ? ['label' => __('Department'), 'value' => $payslip->department_name] : null,
                    ['label' => __('Joined on'), 'value' => $this->date($payslip->joined_on)],
                ])),
            ],
            'period' => [
                'name' => $payslip->period->name,
                'range' => $this->date($payslip->period->starts_on).' - '.$this->date($payslip->period->ends_on),
                'payDate' => $payslip->period->pay_date !== null ? $this->date($payslip->period->pay_date) : null,
            ],
            'earnings' => array_values(array_map($row, $of(LineKind::Earning)->all())),
            'reimbursements' => array_values(array_map($row, $of(LineKind::Reimbursement)->all())),
            'deductions' => array_values(array_map($row, $of(LineKind::Deduction)->all())),
            'employer' => array_values(array_map($row, $of(LineKind::Employer)->all())),
            'totals' => $totals,
            'facts' => $facts,
            'payment' => [
                'status' => match ($payslip->payment_status) {
                    PaymentStatus::Paid => __('Paid'),
                    PaymentStatus::Failed => __('Payment failed'),
                    PaymentStatus::Unpaid => __('Unpaid'),
                },
                'statusKey' => $payslip->payment_status->value,
                'details' => $paid ? array_values(array_filter([
                    $payslip->paid_on !== null ? ['label' => __('Paid on'), 'value' => $this->date($payslip->paid_on)] : null,
                    $payslip->payment_method !== null ? ['label' => __('Method'), 'value' => __($payslip->payment_method->label())] : null,
                    $payslip->payment_reference !== null ? ['label' => __('Reference'), 'value' => $payslip->payment_reference] : null,
                ])) : [],
            ],
            'note' => $this->settings->current()->payslip_note,
            'reversal' => $payslip->is_reversal,
        ];
    }

    private function date(Carbon $date): string
    {
        return $date->copy()->locale(app()->getLocale())->isoFormat('LL');
    }

    /** The workspace logo as a data URI, or null. A missing or unreadable file never blocks the document. */
    private function logo(): ?string
    {
        $path = $this->context->require()->logo_path;

        if ($path === null) {
            return null;
        }

        try {
            $bytes = $this->storage->get($path);

            if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_LOGO_BYTES) {
                return null;
            }

            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            if (! is_string($type) || ! in_array($type, self::LOGO_TYPES, true)) {
                return null;
            }

            return 'data:'.$type.';base64,'.base64_encode($bytes);
        } catch (Throwable) {
            return null;
        }
    }
}

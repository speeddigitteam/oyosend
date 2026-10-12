<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\DaysBasis;
use App\Modules\Payroll\Enums\RateBase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape only. Authorization is the route `can:` permission plus the record scope the
 * controller applies; every id is re-resolved through tenant-scoped models.
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days_basis' => ['required', Rule::enum(DaysBasis::class)],
            'fixed_days' => ['required', 'integer', 'between:1,31'],
            'daily_rate_base' => ['required', Rule::enum(RateBase::class)],
            'deduct_unpaid_leave' => ['required', 'boolean'],
            'deduct_absence' => ['required', 'boolean'],
            'work_day_minutes' => ['required', 'integer', 'between:60,1440'],
            'overtime_multiplier_bps' => ['required', 'integer', 'between:0,100000'],
            'overtime_taxable' => ['required', 'boolean'],
            'max_overtime_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'require_separate_approver' => ['required', 'boolean'],
            'payslip_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_\/]*$/D'],
            'run_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_\/]*$/D'],
            'number_padding' => ['required', 'integer', 'between:1,10'],
            'payslip_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

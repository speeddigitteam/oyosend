<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\DaysBasis;
use App\Modules\Payroll\Enums\RateBase;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per workspace: how a day is valued and how overtime, absence and approval behave.
 *
 * @property string $id
 * @property string $tenant_id
 * @property DaysBasis $days_basis
 * @property int $fixed_days
 * @property RateBase $daily_rate_base
 * @property bool $deduct_unpaid_leave
 * @property bool $deduct_absence
 * @property int $work_day_minutes
 * @property int $overtime_multiplier_bps
 * @property bool $overtime_taxable
 * @property int|null $max_overtime_minutes
 * @property bool $require_separate_approver
 * @property string $payslip_prefix
 * @property string $run_prefix
 * @property int $number_padding
 * @property string|null $payslip_note
 */
class PayrollSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_settings';

    /** @var list<string> */
    protected $fillable = [
        'days_basis', 'fixed_days', 'daily_rate_base', 'deduct_unpaid_leave', 'deduct_absence', 'work_day_minutes',
        'overtime_multiplier_bps', 'overtime_taxable', 'max_overtime_minutes', 'require_separate_approver',
        'payslip_prefix', 'run_prefix', 'number_padding', 'payslip_note',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'days_basis' => DaysBasis::class,
            'fixed_days' => 'integer',
            'daily_rate_base' => RateBase::class,
            'deduct_unpaid_leave' => 'boolean',
            'deduct_absence' => 'boolean',
            'work_day_minutes' => 'integer',
            'overtime_multiplier_bps' => 'integer',
            'overtime_taxable' => 'boolean',
            'max_overtime_minutes' => 'integer',
            'require_separate_approver' => 'boolean',
            'number_padding' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return 'Payroll settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.settings.'.$verb;
    }
}

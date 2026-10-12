<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\PaymentMethod;
use App\Modules\Payroll\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An employee's pay for one run: an immutable snapshot once the run is approved (`locked_at`).
 * Only the payment columns (and the owner link, which follows the person's account) may change
 * afterwards; every money and descriptive column is frozen by this model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $period_id
 * @property string $employee_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string|null $number
 * @property string $employee_number
 * @property string $employee_name
 * @property string|null $designation_name
 * @property string|null $department_name
 * @property string $employment_type
 * @property Carbon $joined_on
 * @property string $currency
 * @property string|null $assignment_id
 * @property string|null $structure_name
 * @property int $payable_days
 * @property int $period_days
 * @property int $basic_amount
 * @property int $gross_amount
 * @property int $taxable_amount
 * @property int $deduction_amount
 * @property int $reimbursement_amount
 * @property int $net_amount
 * @property int $employer_amount
 * @property array<string, mixed>|null $attendance
 * @property list<array<string, mixed>>|null $warnings
 * @property bool $is_reversal
 * @property string|null $reverses_payslip_id
 * @property PaymentStatus $payment_status
 * @property PaymentMethod|null $payment_method
 * @property string|null $payment_reference
 * @property Carbon|null $paid_on
 * @property Carbon|null $paid_at
 * @property Carbon|null $locked_at
 * @property-read Run $run
 * @property-read Period $period
 * @property-read Collection<int, PayslipLine> $lines
 */
class Payslip extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Columns that may still change on a locked payslip. */
    private const array MUTABLE = ['payment_status', 'payment_method', 'payment_reference', 'paid_on', 'paid_at', 'owner_id', 'updated_at'];

    protected $table = 'payroll_payslips';

    /** @var list<string> */
    protected $fillable = [
        'run_id', 'period_id', 'employee_id', 'owner_id', 'department_id', 'branch_id', 'number', 'employee_number',
        'employee_name', 'designation_name', 'department_name', 'employment_type', 'joined_on', 'currency',
        'assignment_id', 'structure_name', 'payable_days', 'period_days', 'basic_amount', 'gross_amount',
        'taxable_amount', 'deduction_amount', 'reimbursement_amount', 'net_amount', 'employer_amount', 'attendance',
        'warnings', 'is_reversal', 'reverses_payslip_id', 'payment_status', 'payment_method', 'payment_reference',
        'paid_on', 'paid_at', 'locked_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'payable_days' => 'integer',
            'period_days' => 'integer',
            'basic_amount' => 'integer',
            'gross_amount' => 'integer',
            'taxable_amount' => 'integer',
            'deduction_amount' => 'integer',
            'reimbursement_amount' => 'integer',
            'net_amount' => 'integer',
            'employer_amount' => 'integer',
            'attendance' => 'array',
            'warnings' => 'array',
            'is_reversal' => 'boolean',
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'paid_on' => 'date',
            'paid_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Payslip $payslip): void {
            if ($payslip->getRawOriginal('locked_at') === null) {
                return;
            }

            $frozen = array_diff(array_keys($payslip->getDirty()), self::MUTABLE);

            if ($frozen !== []) {
                throw new LogicException('A payslip of an approved run is an immutable snapshot ('.implode(', ', $frozen).').');
            }
        });
        static::deleting(static function (Payslip $payslip): void {
            if ($payslip->getRawOriginal('locked_at') !== null) {
                throw new LogicException('A payslip of an approved run cannot be deleted.');
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return HasMany<PayslipLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

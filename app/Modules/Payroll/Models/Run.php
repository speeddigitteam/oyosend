<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A payroll calculation for one period. Once `locked_at` is set the row never changes again:
 * corrections are a new adjustment or reversal run.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $period_id
 * @property RunKind $kind
 * @property RunStatus $status
 * @property string|null $number
 * @property string|null $reverses_run_id
 * @property int|null $regular_marker
 * @property string|null $prepared_by_id
 * @property string|null $submitted_by_id
 * @property string|null $approved_by_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $locked_at
 * @property string $currency
 * @property int $employee_count
 * @property int $gross_amount
 * @property int $deduction_amount
 * @property int $reimbursement_amount
 * @property int $net_amount
 * @property int $employer_amount
 * @property string|null $input_fingerprint
 * @property list<array<string, mixed>>|null $warnings
 * @property list<array<string, mixed>>|null $errors
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read Period $period
 */
class Run extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
        'period_id', 'kind', 'status', 'number', 'reverses_run_id', 'regular_marker', 'prepared_by_id', 'submitted_by_id',
        'approved_by_id', 'submitted_at', 'approved_at', 'locked_at', 'currency', 'employee_count', 'gross_amount',
        'deduction_amount', 'reimbursement_amount', 'net_amount', 'employer_amount', 'input_fingerprint', 'warnings',
        'errors', 'note',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => RunKind::class,
            'status' => RunStatus::class,
            'regular_marker' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'employee_count' => 'integer',
            'gross_amount' => 'integer',
            'deduction_amount' => 'integer',
            'reimbursement_amount' => 'integer',
            'net_amount' => 'integer',
            'employer_amount' => 'integer',
            'warnings' => 'array',
            'errors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Run $run): void {
            if ($run->getRawOriginal('locked_at') !== null) {
                throw new LogicException('A locked payroll run cannot be changed; post an adjustment or reversal run.');
            }
        });
        static::deleting(static function (Run $run): void {
            if ($run->getRawOriginal('locked_at') !== null || $run->status === RunStatus::Approved) {
                throw new LogicException('A locked payroll run cannot be deleted.');
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** Whether an approved reversal run has cancelled this run: it can then be neither paid nor exported. */
    public function hasApprovedReversal(): bool
    {
        return static::query()->where('reverses_run_id', $this->id)->where('status', RunStatus::Approved)->exists();
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'prepared_by_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'approved_by_id');
    }
}

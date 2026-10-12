<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\LedgerKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only leave balance ledger. A balance is the sum of `units` (signed
 * integers, one day = two units); rows are never updated or deleted, mistakes
 * are corrected by a new adjustment or reversal row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property int $leave_year
 * @property int $units
 * @property LedgerKind $kind
 * @property Carbon $effective_on
 * @property string|null $leave_request_id
 * @property string $idempotency_key
 * @property string|null $note
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
class LeaveLedgerEntry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'hrm_leave_ledger';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_year', 'units', 'kind', 'effective_on', 'leave_request_id',
        'idempotency_key', 'note', 'actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['leave_year' => 'integer', 'units' => 'integer', 'kind' => LedgerKind::class, 'effective_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('The leave ledger is append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('The leave ledger is append-only.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}

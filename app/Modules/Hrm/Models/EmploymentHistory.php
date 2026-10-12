<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\HistoryEvent;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The immutable ledger of an employee's employment: one row per change, written
 * in the same transaction as the change. A row is never updated or deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property HistoryEvent $event
 * @property Carbon $effective_on
 * @property array<string, array{from: mixed, to: mixed}> $changes
 * @property string|null $reason
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
class EmploymentHistory extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'hrm_employment_history';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'event', 'effective_on', 'changes', 'reason', 'actor_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['event' => HistoryEvent::class, 'effective_on' => 'date', 'changes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Employment history is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Employment history is immutable.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

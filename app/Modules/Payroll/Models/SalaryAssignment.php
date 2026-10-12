<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One revision of an employee's salary: immutable. A raise is a new row with a later
 * `effective_from`; the model throws on update and delete.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $profile_id
 * @property string $structure_id
 * @property int $revision
 * @property int $basic_amount
 * @property string $currency
 * @property Carbon $effective_from
 * @property string|null $reason
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 * @property-read SalaryStructure $structure
 */
class SalaryAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'payroll_salary_assignments';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'profile_id', 'structure_id', 'revision', 'basic_amount', 'currency', 'effective_from', 'reason', 'actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'basic_amount' => 'integer', 'effective_from' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Salary revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Salary revisions are immutable.');
        });
    }

    /** @return BelongsTo<SalaryStructure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'structure_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }
}

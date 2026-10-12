<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Models\Concerns\FollowsEmployee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $shift_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $assigned_by
 * @property-read Shift $shift
 */
class ShiftAssignment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use FollowsEmployee;
    use HasUlids;

    protected $table = 'hrm_shift_assignments';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'shift_id', 'starts_on', 'ends_on', 'owner_id', 'department_id', 'branch_id', 'assigned_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function auditLabel(): string
    {
        return 'Shift assignment';
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.shift_assignment.'.$verb;
    }
}

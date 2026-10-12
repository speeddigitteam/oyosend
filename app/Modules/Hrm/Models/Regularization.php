<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Models\Concerns\FollowsEmployee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An employee's request to correct a day's attendance. Times are UTC.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $work_date
 * @property Carbon $clock_in_at
 * @property Carbon $clock_out_at
 * @property string $reason
 * @property RegularizationStatus $status
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 */
class Regularization extends Model
{
    use Auditable;
    use BelongsToTenant;
    use FollowsEmployee;
    use HasUlids;

    protected $table = 'hrm_regularizations';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'work_date', 'clock_in_at', 'clock_out_at', 'reason', 'status', 'requested_by',
        'decided_by', 'decided_at', 'decision_note', 'owner_id', 'department_id', 'branch_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'status' => RegularizationStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return 'Attendance regularization '.$this->work_date->toDateString();
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.regularization.'.$verb;
    }
}

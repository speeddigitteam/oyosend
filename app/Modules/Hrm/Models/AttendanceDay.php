<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Models\Concerns\FollowsEmployee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The computed record of one employee on one local date. All minutes are exact
 * integers derived from the entries and the shift in the workspace timezone.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $work_date
 * @property string|null $shift_id
 * @property DayStatus $status
 * @property int $scheduled_minutes
 * @property int $worked_minutes
 * @property int $late_minutes
 * @property int $early_leave_minutes
 * @property int $overtime_minutes
 * @property Carbon|null $first_in_at
 * @property Carbon|null $last_out_at
 * @property int $entries_count
 * @property int $leave_units
 * @property bool $is_closed
 * @property Carbon|null $closed_at
 * @property int $revision
 */
class AttendanceDay extends Model
{
    use BelongsToTenant;
    use FollowsEmployee;
    use HasUlids;

    protected $table = 'hrm_attendance_days';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'work_date', 'shift_id', 'status', 'scheduled_minutes', 'worked_minutes', 'late_minutes',
        'early_leave_minutes', 'overtime_minutes', 'first_in_at', 'last_out_at', 'entries_count', 'leave_units',
        'is_closed', 'closed_at', 'revision', 'owner_id', 'department_id', 'branch_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => DayStatus::class,
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'entries_count' => 'integer',
            'leave_units' => 'integer',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}

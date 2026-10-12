<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\AttendanceSource;
use App\Modules\Hrm\Models\Concerns\FollowsEmployee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One clock-in/out pair, in UTC. `open_marker` is 1 while there is no clock-out and
 * is unique per employee, so the database allows one open entry at most.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $work_date
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property int|null $open_marker
 * @property AttendanceSource $source
 * @property string|null $note
 * @property string|null $created_by
 */
class AttendanceEntry extends Model
{
    use BelongsToTenant;
    use FollowsEmployee;
    use HasUlids;

    protected $table = 'hrm_attendance_entries';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'work_date', 'clock_in_at', 'clock_out_at', 'open_marker', 'source', 'note', 'created_by',
        'owner_id', 'department_id', 'branch_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'open_marker' => 'integer',
            'source' => AttendanceSource::class,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Models\Concerns\FollowsEmployee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $leave_type_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property LeaveSession $start_session
 * @property LeaveSession $end_session
 * @property int $units
 * @property array<string, int> $days
 * @property int $leave_year
 * @property LeaveStatus $status
 * @property string|null $reason
 * @property string|null $submitted_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property-read LeaveType $leaveType
 */
class LeaveRequest extends Model
{
    use Auditable;
    use BelongsToTenant;
    use FollowsEmployee;
    use HasUlids;

    protected $table = 'hrm_leave_requests';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'start_session', 'end_session', 'units', 'days',
        'leave_year', 'status', 'reason', 'submitted_by', 'decided_at', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'owner_id', 'department_id', 'branch_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'start_session' => LeaveSession::class,
            'end_session' => LeaveSession::class,
            'units' => 'integer',
            'days' => 'array',
            'leave_year' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return HasMany<LeaveApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class)->orderBy('id');
    }

    public function auditLabel(): string
    {
        return 'Leave '.$this->starts_on->toDateString().' - '.$this->ends_on->toDateString();
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.leave_request.'.$verb;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\LeaveApprovalFlow;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $code
 * @property bool $is_paid
 * @property bool $tracks_balance
 * @property bool $allows_half_day
 * @property bool $requires_reason
 * @property int|null $max_consecutive_days
 * @property int $min_notice_days
 * @property LeaveApprovalFlow $approval_flow
 * @property bool $is_active
 * @property int $sort_order
 */
class LeaveType extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_leave_types';

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'is_paid', 'tracks_balance', 'allows_half_day', 'requires_reason', 'max_consecutive_days',
        'min_notice_days', 'approval_flow', 'is_active', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'tracks_balance' => 'boolean',
            'allows_half_day' => 'boolean',
            'requires_reason' => 'boolean',
            'max_consecutive_days' => 'integer',
            'min_notice_days' => 'integer',
            'approval_flow' => LeaveApprovalFlow::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<LeavePolicy, $this> */
    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.leave_type.'.$verb;
    }
}

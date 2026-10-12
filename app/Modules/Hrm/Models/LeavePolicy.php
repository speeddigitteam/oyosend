<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\LeaveAccrual;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accrual rules for one leave type. Quantities are integer units (one day = two
 * units, so a half day is representable).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $leave_type_id
 * @property string $name
 * @property list<string>|null $employment_types null = every employment type
 * @property LeaveAccrual $accrual
 * @property int $annual_units
 * @property int $carry_forward_max_units
 * @property int|null $max_balance_units
 * @property int|null $max_units_per_request
 * @property bool $sandwich_rule
 * @property int $min_service_days
 * @property bool $is_active
 */
class LeavePolicy extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_leave_policies';

    /** @var list<string> */
    protected $fillable = [
        'leave_type_id', 'name', 'employment_types', 'accrual', 'annual_units', 'carry_forward_max_units',
        'max_balance_units', 'max_units_per_request', 'sandwich_rule', 'min_service_days', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employment_types' => 'array',
            'accrual' => LeaveAccrual::class,
            'annual_units' => 'integer',
            'carry_forward_max_units' => 'integer',
            'max_balance_units' => 'integer',
            'max_units_per_request' => 'integer',
            'sandwich_rule' => 'boolean',
            'min_service_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.leave_policy.'.$verb;
    }
}

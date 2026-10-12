<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\ItemType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off pay item for an employee and a period: an allowance, bonus (festival bonus included),
 * deduction or reimbursement. Either a fixed `amount` or a share of the full monthly basic.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $period_id
 * @property ItemType $type
 * @property string $name
 * @property int|null $amount
 * @property int|null $basic_bps
 * @property bool $is_taxable
 * @property ItemStatus $status
 * @property string|null $payslip_id
 * @property string|null $note
 * @property string|null $created_by_id
 * @property-read Period $period
 */
class Item extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_items';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'owner_id', 'department_id', 'branch_id', 'period_id', 'type', 'name', 'amount', 'basic_bps',
        'is_taxable', 'status', 'payslip_id', 'note', 'created_by_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'status' => ItemStatus::class,
            'amount' => 'integer',
            'basic_bps' => 'integer',
            'is_taxable' => 'boolean',
        ];
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.item.'.$verb;
    }
}

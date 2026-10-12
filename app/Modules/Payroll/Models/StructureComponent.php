<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\ComponentCalc;
use App\Modules\Payroll\Enums\ComponentKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $structure_id
 * @property string $name
 * @property string $code
 * @property ComponentKind $kind
 * @property ComponentCalc $calc
 * @property int|null $amount
 * @property int|null $rate_bps
 * @property bool $is_taxable
 * @property int $sort_order
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 */
class StructureComponent extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_structure_components';

    /** @var list<string> */
    protected $fillable = [
        'structure_id', 'name', 'code', 'kind', 'calc', 'amount', 'rate_bps', 'is_taxable', 'sort_order',
        'effective_from', 'effective_to', 'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => ComponentKind::class,
            'calc' => ComponentCalc::class,
            'amount' => 'integer',
            'rate_bps' => 'integer',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalaryStructure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'structure_id');
    }

    /** Whether the component applies to any day of the period (`Y-m-d` bounds). */
    public function appliesTo(string $from, string $to): bool
    {
        return $this->is_active
            && ($this->effective_from === null || $this->effective_from->toDateString() <= $to)
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $from);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.component.'.$verb;
    }
}

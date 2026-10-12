<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable set of recurring pay components. The basic salary is per employee (the assignment).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property bool $is_active
 * @property-read Collection<int, StructureComponent> $components
 */
class SalaryStructure extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_structures';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<StructureComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(StructureComponent::class, 'structure_id')->orderBy('sort_order')->orderBy('name');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.structure.'.$verb;
    }
}

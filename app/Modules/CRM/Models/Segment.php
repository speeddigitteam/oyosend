<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CrmEntityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $name
 * @property CrmEntityType $entity_type
 * @property array<string, mixed> $filters
 * @property bool $is_active
 */
class Segment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_segments';

    protected $fillable = ['name', 'entity_type', 'filters', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['entity_type' => CrmEntityType::class, 'filters' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}

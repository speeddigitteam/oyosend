<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property CrmEntityType $entity_type
 * @property string $key
 * @property string $name
 * @property CustomFieldType $type
 * @property bool $is_required
 * @property list<string>|null $options
 * @property bool $is_active
 * @property int $position
 */
class CustomField extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_custom_fields';

    protected $fillable = ['entity_type', 'key', 'name', 'type', 'is_required', 'options', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['entity_type' => CrmEntityType::class, 'type' => CustomFieldType::class, 'is_required' => 'boolean', 'options' => 'array', 'is_active' => 'boolean', 'position' => 'integer'];
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CrmEntityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $custom_field_id
 * @property CrmEntityType $entity_type
 * @property string $entity_id
 * @property mixed $value
 */
class CustomFieldValue extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_custom_field_values';

    protected $fillable = ['custom_field_id', 'entity_type', 'entity_id', 'value'];

    protected function casts(): array
    {
        return ['entity_type' => CrmEntityType::class, 'value' => 'json'];
    }

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}

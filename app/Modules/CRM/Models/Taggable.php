<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CrmEntityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $tag_id
 * @property CrmEntityType $entity_type
 * @property string $entity_id
 */
class Taggable extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'crm_taggables';

    protected $fillable = ['tag_id', 'entity_type', 'entity_id'];

    protected function casts(): array
    {
        return ['entity_type' => CrmEntityType::class];
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}

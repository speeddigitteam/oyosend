<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\CRM\Enums\CrmEntityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property CrmEntityType $entity_type
 * @property string $entity_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property Carbon $created_at
 */
class Attachment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_attachments';

    protected $fillable = ['entity_type', 'entity_id', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['entity_type' => CrmEntityType::class, 'size' => 'integer'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'uploaded_by');
    }

    public function auditLabel(): string
    {
        return $this->original_name;
    }
}

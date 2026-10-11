<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string|null $uploaded_by
 * @property Carbon $created_at
 * @property-read TenantMembership|null $uploader
 */
class Attachment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_attachments';

    protected $fillable = ['entity_type', 'entity_id', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'uploaded_by');
    }
}

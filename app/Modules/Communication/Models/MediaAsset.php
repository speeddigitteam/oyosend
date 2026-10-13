<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Actions\MediaLibrary;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Metadata of a media file in the workspace's TenantStorage. `storage_path` is tenant-relative and never
 * leaves {@see MediaLibrary}; apps hold the row id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $direction
 * @property string $type
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $filename
 * @property string $storage_path
 * @property string|null $provider_media_id
 * @property string|null $number_id
 */
class MediaAsset extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_media';

    /** @var list<string> */
    protected $fillable = ['direction', 'type', 'mime', 'size_bytes', 'sha256', 'filename', 'storage_path', 'provider_media_id', 'number_id'];

    /** @var list<string> */
    protected $hidden = ['storage_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}

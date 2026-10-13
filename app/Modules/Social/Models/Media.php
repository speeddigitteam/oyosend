<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Platforms\MediaInfo;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A file in the workspace media library. The bytes live on the private tenant disk
 * (`TenantStorage`); a browser reaches them only through the authorised file route.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $tenant_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property MediaKind $kind
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property string|null $probe_status
 * @property string $sha256
 * @property string|null $alt_text
 * @property string|null $uploaded_by
 */
class Media extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_media';

    /** @var list<string> */
    protected $fillable = ['name', 'path', 'mime', 'kind', 'size_bytes', 'width', 'height', 'duration_ms', 'probe_status', 'sha256', 'alt_text', 'uploaded_by'];

    /** @var list<string> */
    protected $hidden = ['path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => MediaKind::class, 'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration_ms' => 'integer'];
    }

    /** The facts publishing rules need; unreadable metadata stays null. */
    public function info(): MediaInfo
    {
        return new MediaInfo(
            $this->kind === MediaKind::Video, $this->mime, (int) $this->size_bytes, $this->width, $this->height, $this->duration_ms,
        );
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'social.media.'.$verb;
    }
}

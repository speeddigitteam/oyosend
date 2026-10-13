<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The provider media id a stored file received when uploaded for one number.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $media_id
 * @property string $number_id
 * @property string $provider_media_id
 * @property Carbon $expires_at
 */
class MediaUpload extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_media_uploads';

    /** @var list<string> */
    protected $fillable = ['media_id', 'number_id', 'provider_media_id', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}

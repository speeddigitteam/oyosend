<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $post_id
 * @property string $media_id
 * @property int $position
 * @property-read Media $media
 */
class PostMedia extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_post_media';

    /** @var list<string> */
    protected $fillable = ['post_id', 'media_id', 'position'];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}

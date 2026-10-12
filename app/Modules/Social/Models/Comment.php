<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An internal team comment on a post. Never sent to a platform.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $tenant_id
 * @property string $post_id
 * @property string|null $author_id
 * @property string $body
 * @property-read TenantMembership|null $author
 */
class Comment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_comments';

    /** @var list<string> */
    protected $fillable = ['post_id', 'author_id', 'body'];

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }
}

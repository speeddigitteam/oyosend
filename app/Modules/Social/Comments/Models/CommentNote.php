<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An internal note on an audience comment. Never sent to the platform.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $comment_id
 * @property string|null $author_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read TenantMembership|null $author
 */
class CommentNote extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_comment_notes';

    /** @var list<string> */
    protected $fillable = ['comment_id', 'author_id', 'body'];

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }
}

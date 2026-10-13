<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\PostType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One piece of content, published to one or more connected accounts through its targets.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $tenant_id
 * @property string|null $title
 * @property string|null $body
 * @property string|null $link_url
 * @property PostType $post_type
 * @property list<string>|null $hashtags
 * @property bool $ai_assisted AI suggestions were used on this draft (informational marker for approvers)
 * @property PostStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $published_at
 * @property string|null $campaign_id
 * @property string|null $category_id
 * @property string|null $hashtag_set_id
 * @property string|null $import_id
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property-read Campaign|null $campaign
 * @property-read Category|null $category
 */
class Post extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'social_posts';

    /** @var list<string> */
    protected $fillable = [
        'title', 'body', 'link_url', 'post_type', 'hashtags', 'status', 'scheduled_for', 'published_at', 'campaign_id', 'category_id',
        'hashtag_set_id', 'import_id', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_by',
        'rejected_at', 'rejection_reason', 'ai_assisted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'post_type' => PostType::class,
            'hashtags' => 'array',
            'ai_assisted' => 'boolean',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return HasMany<PostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class, 'post_id');
    }

    /** @return HasMany<PostMedia, $this> */
    public function mediaLinks(): HasMany
    {
        return $this->hasMany(PostMedia::class, 'post_id')->orderBy('position');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')->orderBy('created_at');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** The text a platform receives for this post (before a per-account variant replaces the body). */
    public function composedBody(?string $override = null): string
    {
        $text = trim($override ?? (string) $this->body);
        $tags = array_values(array_filter((array) $this->hashtags, 'is_string'));

        if ($tags !== []) {
            $text = trim($text."\n\n".implode(' ', $tags));
        }

        return $text;
    }

    public function auditLabel(): string
    {
        // Never the body: the audit trail is read by people who may not see the post.
        return $this->title ?? 'Post '.substr((string) $this->id, -6);
    }

    public function auditEventName(string $verb): string
    {
        return 'social.post.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['body'];
    }
}

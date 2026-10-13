<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A comment a person left on a connected Page's post. Distinct from {@see Comment},
 * the internal team discussion on a draft. The text is untrusted: it is cleaned on the way in and only ever
 * rendered as text. `owner_id` is the member it is assigned to (the scope of `social.comments.*`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string|null $post_id
 * @property string $platform
 * @property string $external_id
 * @property string|null $external_post_id
 * @property string|null $parent_external_id
 * @property string|null $parent_id
 * @property string|null $author_external_id
 * @property string|null $author_name
 * @property string|null $message
 * @property bool $has_attachment
 * @property bool $is_from_page
 * @property int $like_count
 * @property bool $liked_by_page
 * @property string|null $permalink
 * @property RemoteState $remote_state
 * @property string $source
 * @property string|null $sentiment
 * @property bool $needs_attention
 * @property string|null $attention_reason
 * @property list<string>|null $tags
 * @property Carbon|null $commented_at
 * @property Carbon $received_at
 * @property Carbon|null $read_at
 * @property string|null $read_by
 * @property Carbon|null $replied_at
 * @property int $reply_count
 * @property Carbon|null $handled_at
 * @property string|null $handled_by
 * @property string|null $last_action_error
 * @property-read SocialAccount $account
 * @property-read Post|null $post
 */
class AudienceComment extends Model
{
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    public const int MESSAGE_MAX = 8000;

    protected $table = 'social_audience_comments';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'post_id', 'platform', 'external_id', 'external_post_id', 'parent_external_id', 'parent_id',
        'author_external_id', 'author_name', 'message', 'has_attachment', 'is_from_page', 'like_count', 'liked_by_page',
        'permalink', 'remote_state', 'source', 'sentiment', 'needs_attention', 'attention_reason', 'tags',
        'commented_at', 'received_at', 'read_at', 'read_by', 'replied_at', 'reply_count', 'handled_at', 'handled_by',
        'last_action_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'is_from_page' => 'boolean',
            'like_count' => 'integer',
            'liked_by_page' => 'boolean',
            'remote_state' => RemoteState::class,
            'needs_attention' => 'boolean',
            'tags' => 'array',
            'commented_at' => 'datetime',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
            'replied_at' => 'datetime',
            'reply_count' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'account_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return HasMany<CommentNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CommentNote::class, 'comment_id');
    }

    public function auditLabel(): string
    {
        return mb_substr((string) ($this->author_name ?? '').': '.(string) $this->message, 0, 80);
    }
}

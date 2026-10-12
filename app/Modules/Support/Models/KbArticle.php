<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\ArticleStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string $body
 * @property ArticleStatus $status
 * @property int $current_version
 * @property string|null $author_id
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read KbCategory|null $category
 */
class KbArticle extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_kb_articles';

    /** @var list<string> */
    protected $fillable = [
        'category_id', 'title', 'slug', 'summary', 'body', 'status', 'current_version', 'author_id', 'published_at', 'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'current_version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }

    /** @return HasMany<KbArticleVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(KbArticleVersion::class, 'article_id')->orderByDesc('version');
    }
}

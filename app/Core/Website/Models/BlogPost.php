<?php

declare(strict_types=1);

namespace App\Core\Website\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Core\Website\Enums\PostKind;
use App\Core\Website\Enums\PostStatus;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An article on the public website's blog (docs/WEBSITE.md, ADR 0037). Platform data: written by the company behind
 * the platform in the Super Admin panel. Its words live in one {@see BlogPostTranslation} per language; an article
 * is read in a language only when it is written in it.
 *
 * @property string $id
 * @property string $slug
 * @property PostKind $kind
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property bool $featured
 * @property list<string>|null $apps
 * @property string|null $cover
 * @property int|null $cover_width
 * @property int|null $cover_height
 * @property string|null $author_name
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, BlogPostTranslation> $translations
 */
class BlogPost extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    /** An address part: lower-case letters and digits in words joined by single hyphens, the same in both languages. */
    public const string SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** Words the blog's own addresses use. */
    public const array RESERVED_SLUGS = ['feed', 'media', 'page', 'topic'];

    protected $fillable = [
        'slug', 'kind', 'status', 'published_at', 'featured', 'apps', 'cover', 'cover_width', 'cover_height',
        'author_name', 'created_by', 'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PostKind::class,
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'featured' => 'boolean',
            'apps' => 'array',
        ];
    }

    /**
     * @return HasMany<BlogPostTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(BlogPostTranslation::class, 'post_id');
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'updated_by');
    }

    /**
     * Articles a visitor may read in a language: published, their time reached, and written in it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeReadableIn(Builder $query, string $locale): void
    {
        $query->where('status', PostStatus::Published)
            ->where('published_at', '<=', now())
            ->whereHas('translations', fn (Builder $translation) => $translation->where('locale', $locale));
    }

    /** The article's words in a language, from the loaded translations. */
    public function in(string $locale): ?BlogPostTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * The languages it is written in, in the website's order.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $written = $this->translations->pluck('locale')->all();

        return array_values(array_filter(['en', 'bn'], static fn (string $locale): bool => in_array($locale, $written, true)));
    }

    /** Readable now: published and its time reached. */
    public function isLive(): bool
    {
        return $this->status === PostStatus::Published && $this->published_at !== null && $this->published_at->isPast();
    }

    public function isScheduled(): bool
    {
        return $this->status === PostStatus::Published && $this->published_at !== null && $this->published_at->isFuture();
    }

    /**
     * The apps it is about, as module keys.
     *
     * @return list<string>
     */
    public function appModules(): array
    {
        return array_values(array_filter($this->apps ?? [], is_string(...)));
    }
}

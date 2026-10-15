<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Website\Enums\PostKind;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\SitePrice;
use App\Core\Website\SiteUrls;
use Illuminate\Support\Carbon;

/**
 * One article as a list, a card or a feed shows it, in one language.
 */
final readonly class PostCard
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $url,
        public string $title,
        public string $summary,
        public PostKind $kind,
        public string $kindLabel,
        public Carbon $published,
        public Carbon $updated,
        public string $date,
        public int $minutes,
        public string $readingTime,
        public ?string $cover,
        public ?int $coverWidth,
        public ?int $coverHeight,
        public string $coverAlt,
        public bool $featured,
    ) {}

    public static function of(BlogPost $post, string $locale): self
    {
        $words = $post->in($locale) ?? $post->translations->firstOrFail();
        $published = ($post->published_at ?? $post->created_at)->copy()->timezone((string) config('site.blog.timezone'));
        $minutes = BlogMarkdown::minutes($words->body, $locale);

        return new self(
            id: $post->id,
            slug: $post->slug,
            url: SiteUrls::to('post', $locale, ['post' => $post->slug]),
            title: $words->title,
            summary: $words->summary,
            kind: $post->kind,
            kindLabel: __("site_pages.blog.kinds.{$post->kind->value}", [], $locale),
            published: $published,
            updated: $post->updated_at->copy()->timezone((string) config('site.blog.timezone')),
            date: SitePrice::digits($published->copy()->locale($locale)->translatedFormat('j F Y'), $locale),
            minutes: $minutes,
            readingTime: trans_choice('site_pages.blog.minutes', $minutes, ['count' => SitePrice::number($minutes, $locale)], $locale),
            cover: $post->cover === null ? null : BlogMedia::url($post->cover),
            coverWidth: $post->cover_width,
            coverHeight: $post->cover_height,
            coverAlt: (string) ($words->cover_alt ?? ''),
            featured: $post->featured,
        );
    }
}

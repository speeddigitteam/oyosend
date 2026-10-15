<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Audit\AuditLogger;
use App\Core\Website\Enums\PostKind;
use App\Core\Website\Enums\PostStatus;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\Models\BlogPostTranslation;
use App\Core\Website\SiteUrls;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes blog articles for the Super Admin panel (Website > Blog): one save is one transaction with its audit entry.
 * The input has been validated by the caller (App\Platform\Http\Controllers\Website\BlogController).
 */
final readonly class BlogEditor
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Create or change an article.
     *
     * @param  array{slug: string, kind: string, status: string, publish_at: string|null, featured: bool, apps: list<string>, author_name: string|null, cover: string|null, translations: array<string, array<string, mixed>>}  $input
     */
    public function save(?BlogPost $post, array $input, ?string $editor): BlogPost
    {
        return DB::transaction(function () use ($post, $input, $editor): BlogPost {
            $creating = $post === null;
            $post ??= new BlogPost(['created_by' => $editor]);
            $wasLive = ! $creating && $post->status === PostStatus::Published;

            $status = PostStatus::from($input['status']);
            $cover = $input['cover'];

            $post->fill([
                'slug' => $input['slug'],
                'kind' => PostKind::from($input['kind']),
                'status' => $status,
                'published_at' => $this->publishedAt($post, $status, $input['publish_at']),
                'featured' => $input['featured'],
                'apps' => array_values(array_unique($input['apps'])),
                'author_name' => $input['author_name'],
                'updated_by' => $editor,
            ]);

            if ($cover !== $post->cover) {
                [$width, $height] = $cover === null ? [null, null] : BlogMedia::dimensions($cover);
                $post->fill(['cover' => $cover, 'cover_width' => $width, 'cover_height' => $height]);
            }

            $post->save();

            // One article leads the blog at a time.
            if ($post->featured) {
                BlogPost::query()->whereKeyNot($post->id)->where('featured', true)->update(['featured' => false]);
            }

            foreach (SiteUrls::LOCALES as $locale) {
                $words = $input['translations'][$locale] ?? null;

                if ($words === null) {
                    BlogPostTranslation::query()->where('post_id', $post->id)->where('locale', $locale)->delete();

                    continue;
                }

                BlogPostTranslation::query()->updateOrCreate(['post_id' => $post->id, 'locale' => $locale], [
                    'title' => $words['title'],
                    'summary' => $words['summary'] ?? '',
                    'body' => $words['body'] ?? '',
                    'cover_alt' => $words['cover_alt'] ?? null,
                    'meta_title' => $words['meta_title'] ?? null,
                    'meta_description' => $words['meta_description'] ?? null,
                    'faq' => $words['faq'] ?? [],
                ]);
            }

            $post->load('translations');
            $label = $this->label($post);
            $details = ['slug' => $post->slug, 'locales' => $post->locales()];

            $this->audit->record($creating ? 'platform.website.post_created' : 'platform.website.post_updated', $post, $details, subjectLabel: $label);

            if (! $wasLive && $status === PostStatus::Published) {
                $this->audit->record('platform.website.post_published', $post, [
                    'published_at' => $post->published_at?->toIso8601String(),
                ], subjectLabel: $label);
            } elseif ($wasLive && $status === PostStatus::Draft) {
                $this->audit->record('platform.website.post_unpublished', $post, subjectLabel: $label);
            }

            return $post;
        });
    }

    public function delete(BlogPost $post): void
    {
        DB::transaction(function () use ($post): void {
            $post->loadMissing('translations');
            // The words go with the row; the trail keeps which article it was.
            $this->audit->record('platform.website.post_deleted', null, ['slug' => $post->slug], subjectLabel: $this->label($post));
            $post->delete();
        });
    }

    /**
     * When the article counts as published: the time chosen, else when it was first published, else now. A draft
     * keeps the time it had, so publishing it again does not move it to the top of the blog.
     */
    private function publishedAt(BlogPost $post, PostStatus $status, ?string $chosen): ?Carbon
    {
        if ($chosen !== null && $chosen !== '') {
            return Carbon::parse($chosen, (string) config('site.blog.timezone'))->utc();
        }

        if ($status === PostStatus::Draft) {
            return $post->published_at;
        }

        return $post->published_at ?? now();
    }

    private function label(BlogPost $post): string
    {
        return (string) ($post->in('en')->title ?? $post->in('bn')->title ?? $post->slug);
    }
}

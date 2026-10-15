<?php

declare(strict_types=1);

namespace App\Core\Website\Blog;

use App\Core\Website\Enums\PostKind;
use App\Core\Website\Models\BlogPost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The articles a visitor may read, in the language of the page (docs/WEBSITE.md). Only published articles whose
 * time has come and that are written in that language: a scheduled article appears by itself when its time comes.
 */
final class BlogPosts
{
    /** @var array<string, bool> */
    private array $exists = [];

    /**
     * @return Builder<BlogPost>
     */
    public function query(string $locale): Builder
    {
        return BlogPost::query()
            ->readableIn($locale)
            ->with('translations')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /** Whether the blog has anything to read in a language: what decides if the website links to it. */
    public function any(string $locale): bool
    {
        return $this->exists[$locale] ??= BlogPost::query()->readableIn($locale)->exists();
    }

    /**
     * @return LengthAwarePaginator<int, BlogPost>
     */
    public function page(string $locale, ?PostKind $kind, ?string $app, int $page, ?string $except = null): LengthAwarePaginator
    {
        return $this->query($locale)
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->when($app !== null, fn (Builder $query) => $query->whereJsonContains('apps', $app))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
            ->paginate((int) config('site.blog.per_page', 12), ['*'], 'page', $page);
    }

    /** The article at the top of the blog: the one marked featured, else the newest. */
    public function lead(string $locale): ?BlogPost
    {
        return $this->query($locale)->reorder()->orderByDesc('featured')->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /**
     * @return list<PostCard>
     */
    public function latest(string $locale, int $limit, ?string $app = null): array
    {
        $posts = $this->query($locale)
            ->when($app !== null, fn (Builder $query) => $query->whereJsonContains('apps', $app))
            ->limit($limit)
            ->get();

        return $this->cards($posts->all(), $locale);
    }

    public function find(string $slug, string $locale): ?BlogPost
    {
        return $this->query($locale)->where('slug', $slug)->first();
    }

    /**
     * Articles to read next: about the same apps or of the same kind first, then the newest.
     *
     * @return list<PostCard>
     */
    public function related(BlogPost $post, string $locale, int $limit = 3): array
    {
        $apps = $post->appModules();

        $close = $this->query($locale)
            ->whereKeyNot($post->id)
            ->where(function (Builder $query) use ($post, $apps): void {
                $query->where('kind', $post->kind);

                foreach ($apps as $app) {
                    $query->orWhereJsonContains('apps', $app);
                }
            })
            ->limit($limit)
            ->get();

        if ($close->count() < $limit) {
            $close = $close->concat($this->query($locale)
                ->whereKeyNot([$post->id, ...$close->modelKeys()])
                ->limit($limit - $close->count())
                ->get());
        }

        return $this->cards($close->all(), $locale);
    }

    /**
     * @param  array<int, BlogPost>  $posts
     * @return list<PostCard>
     */
    public function cards(array $posts, string $locale): array
    {
        return array_values(array_map(static fn (BlogPost $post): PostCard => PostCard::of($post, $locale), $posts));
    }
}

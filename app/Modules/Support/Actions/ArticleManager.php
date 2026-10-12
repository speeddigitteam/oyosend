<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\ArticleStatus;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbArticleVersion;
use App\Modules\Support\Models\KbCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The internal knowledge base: categories, articles with a draft / published / archived lifecycle, and an
 * immutable version row for every save. Editing a published article changes the live text at once and adds
 * a version, so any earlier text can be restored.
 */
final class ArticleManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes title, summary?, body, category_id? */
    public function create(array $attributes, ?TenantMembership $actor): KbArticle
    {
        $this->assertCategory($attributes['category_id'] ?? null);

        return DB::transaction(function () use ($attributes, $actor): KbArticle {
            $article = KbArticle::query()->create([
                'category_id' => $attributes['category_id'] ?? null,
                'title' => $attributes['title'],
                'slug' => $this->slug((string) $attributes['title']),
                'summary' => $attributes['summary'] ?? null,
                'body' => $attributes['body'],
                'status' => ArticleStatus::Draft,
                'current_version' => 1,
                'author_id' => $actor?->id,
            ]);
            $this->snapshot($article, $actor);
            $this->audit->record('support.article.created', $article, ['title' => $article->title]);

            return $article;
        });
    }

    /** @param array<string, mixed> $attributes title, summary?, body, category_id? */
    public function update(KbArticle $article, array $attributes, ?TenantMembership $actor): KbArticle
    {
        $this->assertCategory($attributes['category_id'] ?? null);

        return DB::transaction(function () use ($article, $attributes, $actor): KbArticle {
            $locked = KbArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $changed = $locked->title !== $attributes['title']
                || $locked->body !== $attributes['body']
                || $locked->summary !== ($attributes['summary'] ?? null);

            $locked->category_id = $attributes['category_id'] ?? null;

            if ($changed) {
                $locked->title = (string) $attributes['title'];
                $locked->summary = $attributes['summary'] ?? null;
                $locked->body = (string) $attributes['body'];
                $locked->current_version++;
            }

            $locked->save();

            if ($changed) {
                $this->snapshot($locked, $actor);
                $this->audit->record('support.article.updated', $locked, ['version' => $locked->current_version]);
            }

            return $locked;
        });
    }

    public function publish(KbArticle $article, ?TenantMembership $actor): KbArticle
    {
        return $this->setStatus($article, ArticleStatus::Published, 'support.article.published');
    }

    /** Back to draft: hidden from agents' search until published again. */
    public function unpublish(KbArticle $article, ?TenantMembership $actor): KbArticle
    {
        return $this->setStatus($article, ArticleStatus::Draft, 'support.article.unpublished');
    }

    public function archive(KbArticle $article, ?TenantMembership $actor): KbArticle
    {
        return $this->setStatus($article, ArticleStatus::Archived, 'support.article.archived');
    }

    /** Restores the text of an earlier version as a new version. */
    public function restoreVersion(KbArticle $article, int $version, ?TenantMembership $actor): KbArticle
    {
        $source = KbArticleVersion::query()->where('article_id', $article->id)->where('version', $version)->first()
            ?? throw ValidationException::withMessages(['version' => __('That version does not exist.')]);

        return $this->update($article, [
            'title' => $source->title,
            'summary' => $source->summary,
            'body' => $source->body,
            'category_id' => $article->category_id,
        ], $actor);
    }

    /** @param array<string, mixed> $attributes name, position? */
    public function saveCategory(?KbCategory $category, array $attributes): KbCategory
    {
        return DB::transaction(function () use ($category, $attributes): KbCategory {
            $duplicate = KbCategory::query()->where('name', (string) $attributes['name'])
                ->when($category !== null, fn ($query) => $query->whereKeyNot($category?->id))->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('That name is already in use.')]);
            }

            if ($category === null) {
                $category = new KbCategory(['slug' => $this->categorySlug((string) $attributes['name'])]);
            }

            $category->fill([
                'name' => $attributes['name'],
                'position' => (int) ($attributes['position'] ?? 0),
            ])->save();

            return $category;
        });
    }

    public function deleteCategory(KbCategory $category): void
    {
        DB::transaction(static fn () => $category->delete());
    }

    private function setStatus(KbArticle $article, ArticleStatus $status, string $event): KbArticle
    {
        return DB::transaction(function () use ($article, $status, $event): KbArticle {
            $locked = KbArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            $locked->status = $status;
            $locked->published_at = $status === ArticleStatus::Published ? ($locked->published_at ?? CarbonImmutable::now()) : $locked->published_at;
            $locked->archived_at = $status === ArticleStatus::Archived ? CarbonImmutable::now() : null;
            $locked->save();
            $this->audit->record($event, $locked, ['title' => $locked->title]);

            return $locked;
        });
    }

    private function snapshot(KbArticle $article, ?TenantMembership $actor): void
    {
        KbArticleVersion::query()->create([
            'article_id' => $article->id,
            'version' => $article->current_version,
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body,
            'edited_by' => $actor?->id,
        ]);
    }

    private function assertCategory(mixed $id): void
    {
        if ($id !== null && $id !== '' && ! KbCategory::query()->whereKey((string) $id)->exists()) {
            throw ValidationException::withMessages(['category_id' => __('The selected value is not valid.')]);
        }
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'article';
        $base = mb_substr($base, 0, 200);
        $slug = $base;

        for ($i = 2; KbArticle::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function categorySlug(string $name): string
    {
        $base = mb_substr(Str::slug($name) ?: 'category', 0, 120);
        $slug = $base;

        for ($i = 2; KbCategory::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}

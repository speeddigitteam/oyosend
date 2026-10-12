<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\ArticleManager;
use App\Modules\Support\Enums\ArticleStatus;
use App\Modules\Support\Http\Requests\ArticleRequest;
use App\Modules\Support\Http\Requests\KbCategoryRequest;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbArticleVersion;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Support\SupportPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The internal knowledge base. Everyone with `support.kb.view` reads published articles; drafts, archived
 * articles and version history are for `support.kb.manage`.
 */
final class KnowledgeBaseController
{
    public function __construct(
        private readonly ArticleManager $articles,
        private readonly SupportControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $manage = $actor->can(SupportPermissions::KB_MANAGE);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(ArticleStatus::class)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $manage ? ($filters['status'] ?? null) : ArticleStatus::Published->value;

        $articles = KbArticle::query()
            ->with(['category:id,name,slug'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'like', Like::contains($search))
                ->orWhere('summary', 'like', Like::contains($search))
                ->orWhere('body', 'like', Like::contains($search))))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (KbArticle $article): array => $this->summary($article));

        $counts = KbArticle::query()
            ->when(! $manage, fn ($query) => $query->where('status', ArticleStatus::Published))
            ->selectRaw('category_id, count(*) as aggregate')->groupBy('category_id')->pluck('aggregate', 'category_id');

        return Inertia::render('Modules/Support/Pages/Kb/Index', [
            'articles' => $articles,
            'categories' => KbCategory::query()->orderBy('position')->orderBy('name')->get()
                ->map(fn (KbCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'position' => $category->position,
                    'articleCount' => (int) ($counts[$category->id] ?? 0),
                ])->all(),
            'filters' => ['search' => $search, 'categoryId' => $filters['category_id'] ?? null, 'status' => $status],
            'statuses' => array_map(static fn (ArticleStatus $s): string => $s->value, ArticleStatus::cases()),
            'canManage' => $manage,
            'canPublish' => $actor->can(SupportPermissions::KB_PUBLISH),
        ]);
    }

    public function show(Request $request, KbArticle $article): Response
    {
        $actor = $this->support->actor($request);
        $manage = $actor->can(SupportPermissions::KB_MANAGE);
        abort_unless($manage || $article->status === ArticleStatus::Published, 404);
        $article->load(['category:id,name,slug', 'author.user:id,name']);

        return Inertia::render('Modules/Support/Pages/Kb/Show', [
            'article' => [
                ...$this->summary($article),
                'body' => $article->body,
                'categoryId' => $article->category_id,
                'author' => $article->author?->user->name,
            ],
            'versions' => $manage
                ? KbArticleVersion::query()->where('article_id', $article->id)->with('editor.user:id,name')
                    ->orderByDesc('version')->limit(50)->get()
                    ->map(fn (KbArticleVersion $version): array => [
                        'version' => $version->version,
                        'title' => $version->title,
                        'editedBy' => $version->editor?->user->name,
                        'createdAt' => $version->created_at->toIso8601String(),
                        'isCurrent' => $version->version === $article->current_version,
                    ])->all()
                : [],
            'categories' => KbCategory::query()->orderBy('position')->orderBy('name')->get(['id', 'name'])
                ->map(fn (KbCategory $category): array => ['id' => $category->id, 'name' => $category->name])->all(),
            'abilities' => [
                'edit' => $manage,
                'publish' => $actor->can(SupportPermissions::KB_PUBLISH),
                'archive' => $actor->can(SupportPermissions::KB_PUBLISH),
            ],
        ]);
    }

    public function store(ArticleRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $article = $this->articles->create($request->validated(), $this->support->membership($actor));
        Toast::success(__('Article saved as a draft.'));

        return redirect()->route('support.kb.articles.show', $article);
    }

    public function update(ArticleRequest $request, KbArticle $article): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->articles->update($article, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Article saved.'));

        return back();
    }

    public function publish(Request $request, KbArticle $article): RedirectResponse
    {
        $this->articles->publish($article, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Article published.'));

        return back();
    }

    public function unpublish(Request $request, KbArticle $article): RedirectResponse
    {
        $this->articles->unpublish($article, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Article moved back to drafts.'));

        return back();
    }

    public function archive(Request $request, KbArticle $article): RedirectResponse
    {
        $this->articles->archive($article, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Article archived.'));

        return back();
    }

    public function restore(Request $request, KbArticle $article, int $version): RedirectResponse
    {
        $this->articles->restoreVersion($article, $version, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Version restored.'));

        return back();
    }

    public function storeCategory(KbCategoryRequest $request): RedirectResponse
    {
        $this->articles->saveCategory(null, $request->validated());
        Toast::success(__('Category saved.'));

        return back();
    }

    public function updateCategory(KbCategoryRequest $request, KbCategory $category): RedirectResponse
    {
        $this->articles->saveCategory($category, $request->validated());
        Toast::success(__('Category saved.'));

        return back();
    }

    public function destroyCategory(KbCategory $category): RedirectResponse
    {
        $this->articles->deleteCategory($category);
        Toast::success(__('Category deleted.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(KbArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'status' => $article->status->value,
            'category' => $article->category === null ? null : ['id' => $article->category->id, 'name' => $article->category->name],
            'version' => $article->current_version,
            'publishedAt' => $article->published_at?->toIso8601String(),
            'updatedAt' => $article->updated_at->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Website\Blog\ArticlePage;
use App\Core\Website\Blog\BlogEditor;
use App\Core\Website\Blog\BlogMedia;
use App\Core\Website\Enums\PostKind;
use App\Core\Website\Enums\PostStatus;
use App\Core\Website\Models\BlogPost;
use App\Core\Website\Models\BlogPostTranslation;
use App\Core\Website\SiteApp;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteUrls;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The website's blog in the Super Admin panel (docs/WEBSITE.md, ADR 0037): every article, written in English,
 * Bengali or both, saved as a draft, published now or scheduled, previewed exactly as the website will show it.
 */
final class BlogController
{
    private const int MAX_APPS = 6;

    private const int MAX_QUESTIONS = 10;

    public function __construct(
        private readonly SiteApps $apps,
        private readonly BlogEditor $editor,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'published', 'scheduled', 'draft'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));

        $posts = BlogPost::query()
            ->with(['translations', 'editor:id,name'])
            ->tap(fn (Builder $query) => $this->whereState($query, $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('slug', 'like', Like::contains($search))
                ->orWhereHas('translations', fn (Builder $words) => $words->where('title', 'like', Like::contains($search)))))
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (BlogPost $post): array => $this->summary($post));

        $counts = [];

        foreach (['published', 'scheduled', 'draft'] as $state) {
            $counts[$state] = BlogPost::query()->tap(fn (Builder $query) => $this->whereState($query, $state))->count();
        }

        return Inertia::render('Platform/Pages/Website/Blog/Index', [
            'posts' => $posts,
            'counts' => $counts,
            'filters' => ['status' => $status, 'search' => $search],
            'blogUrl' => SiteUrls::to('blog', 'en'),
        ]);
    }

    public function create(): Response
    {
        return $this->editorPage(null);
    }

    public function edit(BlogPost $post): Response
    {
        $post->load(['translations', 'editor:id,name']);

        return $this->editorPage($post);
    }

    public function store(Request $request): RedirectResponse
    {
        $post = $this->editor->save(null, $this->validated($request, null), $this->editorId($request));

        Toast::success($post->status === PostStatus::Published ? __('Article published.') : __('Draft saved.'));

        return redirect()->route('platform.website.blog.edit', ['post' => $post->id]);
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $wasLive = $post->status === PostStatus::Published;
        $post = $this->editor->save($post->load('translations'), $this->validated($request, $post), $this->editorId($request));

        Toast::success(match (true) {
            $post->status === PostStatus::Draft && $wasLive => __('Article taken off the website. It is a draft again.'),
            $post->status === PostStatus::Draft => __('Draft saved.'),
            $post->isScheduled() => __('Article scheduled.'),
            $wasLive => __('Article updated.'),
            default => __('Article published.'),
        });

        return back();
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $this->editor->delete($post);

        Toast::success(__('Article deleted.'));

        return redirect()->route('platform.website.blog.index');
    }

    /**
     * Keep a picture for an article: its cover or one in its text. Answers with where the website serves it.
     */
    public function upload(Request $request, BlogMedia $media): JsonResponse
    {
        $input = $request->validate([
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:min_width=200,min_height=100,max_width=8000,max_height=8000'],
        ]);

        /** @var UploadedFile $upload */
        $upload = $input['file'];

        try {
            return response()->json($media->store($upload));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['file' => __('This file could not be read as a picture. Use a PNG, JPG or WebP image.')]);
        }
    }

    /**
     * The article as the website will show it, from what is in the editor now, saved or not. Shown by the editor in
     * a sandboxed frame; it is never public and never indexed.
     */
    public function preview(Request $request, ArticlePage $page): HttpResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(SiteUrls::LOCALES)]])['locale'];
        $existing = is_string($request->input('id')) ? BlogPost::query()->find($request->input('id')) : null;
        $input = $this->validated($request, $existing, preview: $locale);

        $post = new BlogPost([
            'slug' => $input['slug'],
            'kind' => PostKind::from($input['kind']),
            'status' => PostStatus::from($input['status']),
            'featured' => $input['featured'],
            'apps' => $input['apps'],
            'author_name' => $input['author_name'],
            'cover' => $input['cover'],
        ]);
        $post->id = $existing->id ?? 'preview';
        [$post->cover_width, $post->cover_height] = $input['cover'] === null ? [null, null] : BlogMedia::dimensions($input['cover']);
        $post->published_at = filled($input['publish_at'])
            ? Carbon::parse((string) $input['publish_at'], (string) config('site.blog.timezone'))->utc()
            : ($existing->published_at ?? now());
        $post->created_at = $existing->created_at ?? now();
        $post->updated_at = now();

        $translations = [];

        foreach ($input['translations'] as $written => $words) {
            if ($words !== null) {
                $translations[] = new BlogPostTranslation([...$words, 'locale' => $written]);
            }
        }

        $post->setRelation('translations', new Collection($translations));

        // The page is drawn in its own language, whatever the panel's.
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = $page->render($post, $locale, preview: true)->render();
        } finally {
            app()->setLocale($previous);
        }

        return response($html, 200, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function editorPage(?BlogPost $post): Response
    {
        return Inertia::render('Platform/Pages/Website/Blog/Edit', [
            'post' => $post === null ? null : $this->detail($post),
            'apps' => array_map(static fn (SiteApp $app): array => [
                'module' => $app->module,
                'name' => $app->text('name'),
                'category' => $app->category,
            ], $this->apps->all()),
            'kinds' => PostKind::values(),
            'timezone' => (string) config('site.blog.timezone'),
            'blogUrl' => SiteUrls::to('blog', 'en'),
            'limits' => ['apps' => self::MAX_APPS, 'questions' => self::MAX_QUESTIONS],
        ]);
    }

    /**
     * The checked input, ready for {@see BlogEditor::save()}. A language counts as written once anything is typed
     * in it; publishing asks for its title, summary and text, a draft only for its title. With `$preview`, only that
     * language has to be there, as far as it goes.
     *
     * @return array{slug: string, kind: string, status: string, publish_at: string|null, featured: bool, apps: list<string>, author_name: string|null, cover: string|null, translations: array<string, array<string, mixed>|null>}
     */
    private function validated(Request $request, ?BlogPost $post, ?string $preview = null): array
    {
        $rules = [
            'slug' => [
                'nullable', 'string', 'max:120', 'regex:/^'.BlogPost::SLUG_PATTERN.'$/', Rule::notIn(BlogPost::RESERVED_SLUGS),
                ...($preview === null ? [Rule::unique(BlogPost::class, 'slug')->ignore($post?->id)] : []),
            ],
            'kind' => ['required', Rule::enum(PostKind::class)],
            'status' => ['required', Rule::enum(PostStatus::class)],
            'publish_at' => ['nullable', 'date'],
            'featured' => ['boolean'],
            'apps' => ['array', 'max:'.self::MAX_APPS],
            'apps.*' => ['string', Rule::in(array_map(static fn (SiteApp $app): string => $app->module, $this->apps->all()))],
            'author_name' => ['nullable', 'string', 'max:120'],
            'cover' => ['nullable', 'string', $this->keptImage()],
            'translations' => ['required', 'array'],
        ];

        foreach (SiteUrls::LOCALES as $locale) {
            $prefix = "translations.{$locale}";
            $rules += [
                $prefix => ['nullable', 'array'],
                "{$prefix}.title" => ['nullable', 'string', 'max:160'],
                "{$prefix}.summary" => ['nullable', 'string', 'max:400'],
                "{$prefix}.body" => ['nullable', 'string', 'max:100000'],
                "{$prefix}.cover_alt" => ['nullable', 'string', 'max:200'],
                "{$prefix}.meta_title" => ['nullable', 'string', 'max:70'],
                "{$prefix}.meta_description" => ['nullable', 'string', 'max:170'],
                "{$prefix}.faq" => ['nullable', 'array', 'max:'.self::MAX_QUESTIONS],
                "{$prefix}.faq.*.q" => ['nullable', 'string', 'max:200'],
                "{$prefix}.faq.*.a" => ['nullable', 'string', 'max:1000'],
            ];
        }

        $input = $request->validate($rules);
        $publishing = $input['status'] === PostStatus::Published->value;
        $errors = [];
        $translations = [];

        foreach (SiteUrls::LOCALES as $locale) {
            $words = $this->words((array) ($input['translations'][$locale] ?? []));
            $prefix = "translations.{$locale}";

            if ($words === null || ($preview !== null && $preview !== $locale && $words['title'] === '')) {
                $translations[$locale] = null;

                continue;
            }

            $required = $publishing && $preview === null ? ['title', 'summary', 'body'] : ['title'];

            foreach ($required as $field) {
                if ($words[$field] === '') {
                    $errors["{$prefix}.{$field}"] = match ($field) {
                        'title' => __('Give the article a title.'),
                        'summary' => __('Write the short version: it opens the article.'),
                        default => __('Write the article before publishing it.'),
                    };
                }
            }

            foreach ($words['faq'] as $index => $item) {
                if (($item['q'] === '') !== ($item['a'] === '')) {
                    $errors["{$prefix}.faq.{$index}"] = __('Write both the question and its answer, or neither.');
                }
            }

            $words['faq'] = array_values(array_filter($words['faq'], static fn (array $item): bool => $item['q'] !== '' && $item['a'] !== ''));
            $translations[$locale] = $words;
        }

        if ($preview !== null && $translations[$preview] === null) {
            $errors["translations.{$preview}.title"] = __('Give the article a title.');
        }

        if (array_filter($translations) === []) {
            $errors['translations'] = __('Write the article in English, Bengali or both.');
        }

        $slug = trim((string) ($input['slug'] ?? ''));

        if ($slug === '') {
            $slug = $this->slugFrom((string) ($translations['en']['title'] ?? ''), $post);

            if ($slug === '' && $preview === null) {
                $errors['slug'] = __('Give the article an address: lower-case letters, digits and hyphens.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'slug' => $slug === '' ? 'preview' : $slug,
            'kind' => (string) $input['kind'],
            'status' => (string) $input['status'],
            'publish_at' => filled($input['publish_at'] ?? null) ? (string) $input['publish_at'] : null,
            'featured' => (bool) ($input['featured'] ?? false),
            'apps' => array_values(array_unique(array_map(strval(...), $input['apps'] ?? []))),
            'author_name' => filled($input['author_name'] ?? null) ? trim((string) $input['author_name']) : null,
            'cover' => filled($input['cover'] ?? null) ? (string) $input['cover'] : null,
            'translations' => $translations,
        ];
    }

    /**
     * One language's words, trimmed; null when nothing at all is written in it.
     *
     * @param  array<string, mixed>  $raw
     * @return array{title: string, summary: string, body: string, cover_alt: string|null, meta_title: string|null, meta_description: string|null, faq: list<array{q: string, a: string}>}|null
     */
    private function words(array $raw): ?array
    {
        $text = static fn (string $key): string => trim(str_replace("\r\n", "\n", (string) ($raw[$key] ?? '')));
        $optional = static fn (string $key): ?string => $text($key) === '' ? null : $text($key);

        $faq = [];

        foreach ((array) ($raw['faq'] ?? []) as $item) {
            $faq[] = ['q' => trim((string) ($item['q'] ?? '')), 'a' => trim((string) ($item['a'] ?? ''))];
        }

        $words = [
            'title' => $text('title'),
            'summary' => $text('summary'),
            'body' => $text('body'),
            'cover_alt' => $optional('cover_alt'),
            'meta_title' => $optional('meta_title'),
            'meta_description' => $optional('meta_description'),
            'faq' => $faq,
        ];

        $written = $words['title'] !== '' || $words['summary'] !== '' || $words['body'] !== ''
            || array_filter($faq, static fn (array $item): bool => $item['q'] !== '' || $item['a'] !== '') !== [];

        return $written ? $words : null;
    }

    /** An address from the English title, made unique; empty when the title has no Latin letters or digits. */
    private function slugFrom(string $title, ?BlogPost $post): string
    {
        $base = trim(Str::limit(Str::slug($title), 80, ''), '-');

        if ($base === '' || in_array($base, BlogPost::RESERVED_SLUGS, true)) {
            return '';
        }

        $slug = $base;

        for ($n = 2; BlogPost::query()->where('slug', $slug)->whereKeyNot($post->id ?? '')->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** A picture this blog kept: its name, and the file still there. */
    private function keptImage(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! BlogMedia::isFile($value) || ! BlogMedia::disk()->exists(BlogMedia::path($value))) {
                $fail(__('Upload the picture again.'));
            }
        };
    }

    /**
     * @param  Builder<BlogPost>  $query
     */
    private function whereState(Builder $query, string $state): void
    {
        match ($state) {
            'published' => $query->where('status', PostStatus::Published)->where('published_at', '<=', now()),
            'scheduled' => $query->where('status', PostStatus::Published)->where('published_at', '>', now()),
            'draft' => $query->where('status', PostStatus::Draft),
            default => null,
        };
    }

    private function state(BlogPost $post): string
    {
        return match (true) {
            $post->status === PostStatus::Draft => 'draft',
            $post->isScheduled() => 'scheduled',
            default => 'published',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(BlogPost $post): array
    {
        $locales = $post->locales();

        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->in('en')->title ?? $post->in('bn')->title ?? $post->slug,
            'locales' => $locales,
            'kind' => $post->kind->value,
            'state' => $this->state($post),
            'featured' => $post->featured,
            'cover' => $post->cover === null ? null : BlogMedia::url($post->cover),
            'publishedAt' => $post->published_at?->toIso8601String(),
            'updatedAt' => $post->updated_at->toIso8601String(),
            'editor' => $post->editor?->name,
            'url' => $post->isLive() && $locales !== [] ? SiteUrls::to('post', $locales[0], ['post' => $post->slug]) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(BlogPost $post): array
    {
        $translations = [];

        foreach (SiteUrls::LOCALES as $locale) {
            $words = $post->in($locale);
            $translations[$locale] = $words === null ? null : [
                'title' => $words->title,
                'summary' => $words->summary,
                'body' => $words->body,
                'cover_alt' => $words->cover_alt,
                'meta_title' => $words->meta_title,
                'meta_description' => $words->meta_description,
                'faq' => $words->questions(),
            ];
        }

        return [
            ...$this->summary($post),
            'status' => $post->status->value,
            // As the editor's date-and-time field writes it, in the company's time zone.
            'publishAt' => $post->published_at?->copy()->timezone((string) config('site.blog.timezone'))->format('Y-m-d\TH:i'),
            'apps' => $post->appModules(),
            'authorName' => $post->author_name,
            'coverFile' => $post->cover,
            'translations' => $translations,
            'urls' => array_combine($post->locales(), array_map(
                static fn (string $locale): string => SiteUrls::to('post', $locale, ['post' => $post->slug]),
                $post->locales(),
            )),
        ];
    }

    private function editorId(Request $request): ?string
    {
        $id = $request->user('platform')?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}

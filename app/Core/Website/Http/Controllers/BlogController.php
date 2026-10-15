<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Controllers;

use App\Core\Website\Blog\ArticlePage;
use App\Core\Website\Blog\BlogMarkdown;
use App\Core\Website\Blog\BlogPosts;
use App\Core\Website\Blog\PostCard;
use App\Core\Website\Enums\PostKind;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteCompany;
use App\Core\Website\SitePages;
use App\Core\Website\SitePrice;
use App\Core\Website\SiteSchema;
use App\Core\Website\SiteUrls;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The website's blog (docs/WEBSITE.md, ADR 0037): the list of articles in the page's language, one article, and an
 * Atom feed per language. Articles are written in the Super Admin panel (Website > Blog).
 */
final class BlogController
{
    private const int FEED_SIZE = 20;

    public function __construct(
        private readonly BlogPosts $blog,
        private readonly SitePages $pages,
        private readonly SiteApps $apps,
        private readonly SiteCompany $company,
    ) {}

    public function index(Request $request): View
    {
        $locale = app()->getLocale();

        // A filter that is not one is not a page.
        $topic = $request->query('topic');
        $kind = is_string($topic) ? PostKind::tryFrom($topic) : null;
        abort_if($topic !== null && $kind === null, 404);

        $appSlug = $request->query('app');
        $app = is_string($appSlug) ? $this->apps->find($appSlug) : null;
        abort_if($appSlug !== null && $app === null, 404);

        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($page === false, 404);

        $filtered = $kind !== null || $app !== null;

        // The article at the top, on the blog's own first page; it is not listed again below.
        $lead = $filtered ? null : $this->blog->lead($locale);
        $posts = $this->blog->page($locale, $kind, $app?->module, $page, $lead?->id);
        abort_if($page > 1 && $page > $posts->lastPage(), 404);

        $leadCard = $lead !== null && $page === 1 ? PostCard::of($lead, $locale) : null;
        $cards = $this->blog->cards($posts->items(), $locale);

        $query = array_filter(['topic' => $kind?->value, 'app' => $app?->slug]);
        $link = static fn (int $number): string => SiteUrls::to('blog', $locale, [...$query, ...($number > 1 ? ['page' => (string) $number] : [])]);
        $url = $link($page);

        $title = __('site_pages.blog.meta_title');
        $description = __('site_pages.blog.meta_description');

        $meta = $this->pages->meta(
            'blog',
            $page > 1 ? __('site_pages.blog.page_title', ['title' => $title, 'page' => SitePrice::number($page)]) : $title,
            $description,
            parameters: $page > 1 ? ['page' => (string) $page] : [],
            trail: [[__('site.nav.blog'), SiteUrls::to('blog', $locale)]],
            schema: [SiteSchema::blog($url, $title, $description, $locale, array_values(array_filter([$leadCard, ...$cards])))],
            pageType: 'CollectionPage',
        )->with([
            // A filtered list is a view of the blog, not a page of its own; an empty blog has nothing to find.
            'index' => ! $filtered && ($leadCard !== null || $cards !== []),
            'canonical' => $url,
            'feed' => SiteUrls::to('blog_feed', $locale),
            'fallbacks' => [SiteUrls::otherLocale($locale) => SiteUrls::to('blog', SiteUrls::otherLocale($locale))],
        ]);

        return $this->pages->render('site.pages.blog', $meta, [
            'lead' => $leadCard,
            'posts' => $cards,
            'kind' => $kind,
            'app' => $app,
            'topics' => array_map(static fn (PostKind $option): array => [
                'value' => $option->value,
                'label' => __("site_pages.blog.kinds.{$option->value}"),
                'url' => SiteUrls::to('blog', $locale, array_filter(['topic' => $option->value, 'app' => $app?->slug])),
                'current' => $option === $kind,
            ], PostKind::cases()),
            'allUrl' => SiteUrls::to('blog', $locale, array_filter(['app' => $app?->slug])),
            'pagination' => [
                'current' => $posts->currentPage(),
                'last' => $posts->lastPage(),
                'previous' => $posts->currentPage() > 1 ? $link($posts->currentPage() - 1) : null,
                'next' => $posts->hasMorePages() ? $link($posts->currentPage() + 1) : null,
                'pages' => array_map(static fn (int $number): array => ['number' => $number, 'url' => $link($number)], range(1, max(1, $posts->lastPage()))),
            ],
            'otherHasPosts' => $this->blog->any(SiteUrls::otherLocale($locale)),
        ]);
    }

    public function show(string $post, ArticlePage $page): View
    {
        $found = $this->blog->find($post, app()->getLocale());
        abort_if($found === null, 404);

        return $page->render($found, app()->getLocale());
    }

    /**
     * The newest articles in the page's language as an Atom feed (RFC 4287), for feed readers.
     */
    public function feed(BlogMarkdown $markdown): Response
    {
        $locale = app()->getLocale();
        $posts = $this->blog->query($locale)->limit(self::FEED_SIZE)->get();
        $self = SiteUrls::to('blog_feed', $locale);
        $updated = $posts->max(fn ($post) => $post->updated_at->max($post->published_at)) ?? now();

        $xml = ['<?xml version="1.0" encoding="utf-8"?>'];
        $xml[] = '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="'.e($locale).'">';
        $xml[] = '  <title>'.e(__('site_pages.blog.feed_title', ['name' => $this->company->name()])).'</title>';
        $xml[] = '  <subtitle>'.e(__('site_pages.blog.meta_description')).'</subtitle>';
        $xml[] = '  <link rel="self" type="application/atom+xml" href="'.e($self).'"/>';
        $xml[] = '  <link rel="alternate" type="text/html" href="'.e(SiteUrls::to('blog', $locale)).'"/>';
        $xml[] = '  <id>'.e($self).'</id>';
        $xml[] = '  <updated>'.$updated->toAtomString().'</updated>';
        $xml[] = '  <author><name>'.e($this->company->name()).'</name></author>';

        foreach ($posts as $post) {
            $card = PostCard::of($post, $locale);
            $words = $post->in($locale);

            $xml[] = '  <entry>';
            $xml[] = '    <title>'.e($card->title).'</title>';
            $xml[] = '    <link rel="alternate" type="text/html" href="'.e($card->url).'"/>';
            $xml[] = '    <id>'.e($card->url).'</id>';
            $xml[] = '    <published>'.$card->published->toAtomString().'</published>';
            $xml[] = '    <updated>'.$card->updated->max($card->published)->toAtomString().'</updated>';

            if (filled($post->author_name)) {
                $xml[] = '    <author><name>'.e((string) $post->author_name).'</name></author>';
            }

            $xml[] = '    <category term="'.e($card->kind->value).'" label="'.e($card->kindLabel).'"/>';
            $xml[] = '    <summary>'.e($card->summary).'</summary>';
            $xml[] = '    <content type="html">'.e($markdown->render((string) $words?->body, $locale)->html).'</content>';
            $xml[] = '  </entry>';
        }

        $xml[] = '</feed>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }
}

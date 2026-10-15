<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Controllers;

use App\Core\Website\Blog\BlogPosts;
use App\Core\Website\Blog\PostCard;
use App\Core\Website\SiteApp;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteCompany;
use App\Core\Website\SiteUrls;
use Illuminate\Http\Response;

/**
 * The files crawlers ask for on the central site: robots.txt, sitemap.xml and
 * llms.txt (a plain-language map of the website for AI assistants,
 * https://llmstxt.org). Workspaces and the Super Admin panel answer none of
 * them and send `X-Robots-Tag: noindex` instead.
 */
final class SiteFilesController
{
    /** Crawlers of search and AI answer engines, welcomed by name. */
    private const array AI_CRAWLERS = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User',
        'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'Bingbot',
    ];

    /** Addresses with nothing to index: one-time sign-up links and form endpoints. */
    private const array PRIVATE_PATHS = ['/register/confirm/', '/register/sent', '/workspace', '/locale'];

    /** Pages with no parameter, in sitemap order. */
    private const array PAGES = ['home', 'apps', 'pricing', 'security', 'about', 'faq', 'contact', 'signin', 'privacy', 'terms', 'refund'];

    public function __construct(
        private readonly SiteApps $apps,
        private readonly SiteCompany $company,
        private readonly BlogPosts $blog,
    ) {}

    public function robots(): Response
    {
        $rules = array_map(static fn (string $path): string => "Disallow: {$path}", self::PRIVATE_PATHS);
        $group = implode("\n", ['Allow: /', ...$rules]);

        $lines = [
            '# '.$this->company->name().' website. Workspaces and the admin panel are not indexed.',
            'User-agent: *',
            $group,
            '',
            '# Answer engines and AI assistants may read and cite the website.',
            ...array_map(static fn (string $agent): string => "User-agent: {$agent}", self::AI_CRAWLERS),
            $group,
            '',
            'Sitemap: '.route('central.sitemap'),
        ];

        return $this->text(implode("\n", $lines)."\n");
    }

    public function sitemap(): Response
    {
        $updated = (string) config('site.content_updated');
        $entries = [];

        foreach (self::PAGES as $page) {
            $entries[] = ['alternates' => SiteUrls::alternates($page), 'updated' => $updated];
        }

        foreach ($this->apps->all() as $app) {
            $entries[] = ['alternates' => SiteUrls::alternates('app', ['app' => $app->slug]), 'updated' => $updated];
        }

        // The blog in each language it has articles in, and every article in the languages it is written in.
        $blog = array_filter(SiteUrls::alternates('blog'), fn (string $url, string $locale): bool => $this->blog->any($locale), ARRAY_FILTER_USE_BOTH);

        if ($blog !== []) {
            $entries[] = ['alternates' => $blog, 'updated' => $updated];
        }

        $posts = [];

        foreach (SiteUrls::LOCALES as $locale) {
            foreach ($this->blog->query($locale)->get() as $post) {
                $posts[$post->id] = $post;
            }
        }

        foreach ($posts as $post) {
            $alternates = [];

            foreach ($post->locales() as $locale) {
                $alternates[$locale] = SiteUrls::to('post', $locale, ['post' => $post->slug]);
            }

            $entries[] = ['alternates' => $alternates, 'updated' => $post->updated_at->max($post->published_at)->toDateString()];
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

        foreach ($entries as ['alternates' => $alternates, 'updated' => $lastmod]) {
            foreach ($alternates as $url) {
                $xml[] = '  <url>';
                $xml[] = '    <loc>'.e($url).'</loc>';
                $xml[] = '    <lastmod>'.e($lastmod).'</lastmod>';

                foreach ($alternates as $locale => $alternate) {
                    $xml[] = '    <xhtml:link rel="alternate" hreflang="'.e($locale).'" href="'.e($alternate).'"/>';
                }

                $xml[] = '    <xhtml:link rel="alternate" hreflang="x-default" href="'.e($alternates[SiteUrls::DEFAULT_LOCALE] ?? $url).'"/>';
                $xml[] = '  </url>';
            }
        }

        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function llms(): Response
    {
        $en = 'en';

        $lines = [
            '# '.$this->company->name(),
            '',
            '> '.__('site.meta.description', [], $en),
            '',
            __('site.llms.summary', [], $en),
            '',
            '## '.__('site.llms.facts', [], $en),
            '',
        ];

        $facts = __('site.llms.fact_list', [], $en);

        foreach (is_array($facts) ? $facts : [] as $fact) {
            $lines[] = '- '.$fact;
        }

        $lines[] = '';
        $lines[] = '## '.__('site.llms.pages', [], $en);
        $lines[] = '';

        foreach (['apps', 'pricing', 'security', 'about', 'faq', 'contact'] as $page) {
            $lines[] = '- ['.__("site.nav.{$page}", [], $en).']('.SiteUrls::to($page, $en).'): '.__("site_pages.{$page}.meta_description", [], $en);
        }

        $lines[] = '- ['.__('site_legal.privacy.title', [], $en).']('.SiteUrls::to('privacy', $en).')';
        $lines[] = '- ['.__('site_legal.terms.title', [], $en).']('.SiteUrls::to('terms', $en).')';
        $lines[] = '- ['.__('site_legal.refund.title', [], $en).']('.SiteUrls::to('refund', $en).')';
        $lines[] = '';
        $lines[] = '## '.__('site.llms.apps', [], $en);
        $lines[] = '';

        foreach ($this->apps->all() as $app) {
            $lines[] = '- ['.$this->appText($app, 'name', $en).']('.SiteUrls::to('app', $en, ['app' => $app->slug]).'): '.$this->appText($app, 'answer', $en);
        }

        $articles = $this->blog->latest($en, 20);

        if ($articles !== []) {
            $lines[] = '';
            $lines[] = '## '.__('site.llms.blog', [], $en);
            $lines[] = '';

            foreach ($articles as $article) {
                /** @var PostCard $article */
                $lines[] = '- ['.$article->title.']('.$article->url.'): '.$article->summary;
            }
        }

        $lines[] = '';
        $lines[] = '## '.__('site.llms.bengali', [], $en);
        $lines[] = '';
        $lines[] = __('site.llms.bengali_body', [], $en).' '.SiteUrls::to('home', 'bn');
        $lines[] = '';
        $lines[] = '## '.__('site.llms.contact', [], $en);
        $lines[] = '';
        $lines[] = '- Email: '.$this->company->email();

        foreach ($this->company->phones() as $phone) {
            $lines[] = '- Phone: '.$phone['display'];
        }

        $lines[] = '- Address: '.implode(', ', $this->company->addressLines($en));

        foreach ($this->company->social() as $network => $url) {
            $lines[] = '- '.ucfirst($network).': '.$url;
        }

        return $this->text(implode("\n", $lines)."\n", 'text/markdown');
    }

    private function appText(SiteApp $app, string $key, string $locale): string
    {
        return (string) __("site_apps.items.{$app->module}.{$key}", [], $locale);
    }

    private function text(string $body, string $type = 'text/plain'): Response
    {
        return response($body, 200, ['Content-Type' => "{$type}; charset=UTF-8"]);
    }
}

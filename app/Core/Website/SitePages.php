<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Settings\Enums\RegistrationMode;
use App\Core\Settings\PlatformSettings;
use App\Core\Website\Blog\BlogPosts;
use Illuminate\Contracts\View\View;

/**
 * What every website page shares: its frame (header, footer, the sign-up state) and the facts it tells search and
 * answer engines about itself (docs/WEBSITE.md). Used by the pages and the blog alike.
 */
final readonly class SitePages
{
    public function __construct(
        private SiteApps $apps,
        private PlatformSettings $settings,
        private SiteCompany $company,
        private BlogPosts $blog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, PageMeta $meta, array $data = []): View
    {
        return view($view, [
            ...$data,
            'meta' => $meta,
            'company' => $this->company,
            'registrationOpen' => $this->registrationOpen(),
            'navGroups' => $this->apps->byCategory(),
            'hasBlog' => $this->blog->any($meta->locale),
        ]);
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  list<array{0: string, 1: string}>  $trail  from the first page under home to this one
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, string>|null  $alternates  the languages the page exists in, when not both
     * @param  string|null  $modified  when its words last changed; the blog's pages say it on the article instead
     */
    public function meta(
        string $page,
        string $title,
        string $description,
        array $parameters = [],
        array $trail = [],
        array $schema = [],
        bool $fullTitle = false,
        string $pageType = 'WebPage',
        ?array $alternates = null,
        ?string $modified = null,
    ): PageMeta {
        $locale = app()->getLocale();
        $url = SiteUrls::to($page, $locale, $parameters);
        $brand = $this->company->name();
        $name = $fullTitle || str_contains($title, $brand) ? $title : $title.' | '.$brand;
        $crumbs = $trail === [] ? [] : [[__('site.nav.home'), SiteUrls::to('home', $locale)], ...$trail];

        $graph = [
            SiteSchema::organization(),
            SiteSchema::website($locale),
            SiteSchema::webPage($url, $name, $description, $locale, $crumbs, $pageType, $modified),
        ];

        if ($crumbs !== []) {
            $graph[] = SiteSchema::breadcrumbs($url, $crumbs);
        }

        return new PageMeta($name, $description, $url, $alternates ?? SiteUrls::alternates($page, $parameters), $locale, [...$graph, ...$schema]);
    }

    public function registrationOpen(): bool
    {
        return $this->settings->registrationMode() !== RegistrationMode::Closed;
    }
}

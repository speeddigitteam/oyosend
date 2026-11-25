<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Billing\Support\Money;
use App\Core\Website\Blog\PostCard;

/**
 * Schema.org nodes for the website's JSON-LD (https://schema.org). They say
 * the same things as the visible page, in a form search and answer engines
 * read without guessing: who the company is, what the page is, its questions
 * and answers, and where it sits in the site.
 *
 * Nothing here claims more than the page shows: no ratings, no reviews.
 */
final class SiteSchema
{
    public static function root(): string
    {
        return rtrim(SiteUrls::to('home', SiteUrls::DEFAULT_LOCALE), '/');
    }

    public static function organizationId(): string
    {
        return self::root().'/#organization';
    }

    public static function websiteId(): string
    {
        return self::root().'/#website';
    }

    /**
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        $company = app(SiteCompany::class);
        $address = $company->address();
        $phones = $company->phones();

        $node = [
            '@type' => 'Organization',
            '@id' => self::organizationId(),
            'name' => $company->name(),
            'legalName' => $company->legalName(),
            'description' => __('site.meta.description'),
            'foundingDate' => $company->founded(),
            // The home page's own address, written as its canonical link writes it.
            'url' => self::root(),
            // An uploaded logo when there is one, else the built-in mark.
            'logo' => app(SiteBrand::class)->url('logo') !== null
                ? ['@type' => 'ImageObject', 'url' => app(SiteBrand::class)->url('logo')]
                : ['@type' => 'ImageObject', 'url' => asset('images/oyosend-logo.png'), 'width' => 512, 'height' => 512],
            'email' => $company->email(),
            'telephone' => $phones[0]['e164'] ?? null,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $address['street'],
                'addressLocality' => trim($address['locality'].', '.$address['city'], ', '),
                'addressRegion' => $address['city'],
                'postalCode' => $address['postal_code'],
                'addressCountry' => $address['country'],
            ],
            'contactPoint' => array_map(static fn (array $phone, int $index): array => [
                '@type' => 'ContactPoint',
                'telephone' => $phone['e164'],
                'email' => $company->email(),
                'contactType' => $index === 0 ? 'sales' : 'customer support',
                'areaServed' => 'BD',
                'availableLanguage' => ['English', 'Bengali'],
            ], $phones, array_keys($phones)),
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
        ];

        // Said only once the operator has given them (Super Admin > Website > Company).
        $node = array_filter($node, static fn (mixed $value): bool => $value !== null);

        // The company's own pages elsewhere tell engines these profiles are the same organisation.
        if ($company->social() !== []) {
            $node['sameAs'] = array_values($company->social());
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public static function website(string $locale): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::websiteId(),
            'url' => self::root(),
            'name' => app(SiteCompany::class)->name(),
            'description' => __('site.meta.description', [], $locale),
            'inLanguage' => ['en', 'bn'],
            'publisher' => ['@id' => self::organizationId()],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail  [name, url] from the home page to this one
     * @param  string|null  $modified  when the page's words last changed (Y-m-d), for answer engines that weigh freshness
     * @return array<string, mixed>
     */
    public static function webPage(string $url, string $name, string $description, string $locale, array $trail = [], string $type = 'WebPage', ?string $modified = null): array
    {
        $page = [
            '@type' => $type,
            '@id' => $url.'#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'inLanguage' => $locale,
            'isPartOf' => ['@id' => self::websiteId()],
            'about' => ['@id' => self::organizationId()],
        ];

        if ($modified !== null && $modified !== '') {
            $page['dateModified'] = $modified;
        }

        if ($trail !== []) {
            $page['breadcrumb'] = ['@id' => $url.'#breadcrumb'];
        }

        return $page;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail
     * @return array<string, mixed>
     */
    public static function breadcrumbs(string $url, array $trail): array
    {
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $url.'#breadcrumb',
            'itemListElement' => array_map(static fn (array $crumb, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ], $trail, array_keys($trail)),
        ];
    }

    /**
     * @param  list<array{q: string, a: string}>  $questions
     * @return array<string, mixed>
     */
    public static function faq(string $url, array $questions): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $url.'#faq',
            'url' => $url,
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ], $questions),
        ];
    }

    /**
     * The blog, with the articles the page lists.
     *
     * @param  list<PostCard>  $posts
     * @return array<string, mixed>
     */
    public static function blog(string $url, string $name, string $description, string $locale, array $posts): array
    {
        return [
            '@type' => 'Blog',
            '@id' => SiteUrls::to('blog', $locale).'#blog',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'inLanguage' => $locale,
            'publisher' => ['@id' => self::organizationId()],
            'blogPost' => array_map(static fn (PostCard $post): array => [
                '@type' => 'BlogPosting',
                '@id' => $post->url.'#article',
                'headline' => $post->title,
                'url' => $post->url,
                'datePublished' => $post->published->toIso8601String(),
            ], $posts),
        ];
    }

    /**
     * One article: who wrote it and when, how long it is, and the apps it is about.
     *
     * @param  list<array{name: string, url: string}>  $mentions
     * @return array<string, mixed>
     */
    public static function article(PostCard $post, string $description, string $locale, ?string $author, int $words, array $mentions = []): array
    {
        $node = [
            '@type' => 'BlogPosting',
            '@id' => $post->url.'#article',
            'url' => $post->url,
            'mainEntityOfPage' => ['@id' => $post->url.'#webpage'],
            'headline' => mb_substr($post->title, 0, 110),
            'description' => $description,
            'inLanguage' => $locale,
            'datePublished' => $post->published->toIso8601String(),
            'dateModified' => $post->updated->max($post->published)->toIso8601String(),
            'author' => $author !== null && $author !== ''
                ? ['@type' => 'Person', 'name' => $author]
                : ['@id' => self::organizationId()],
            'publisher' => ['@id' => self::organizationId()],
            'isPartOf' => ['@id' => SiteUrls::to('blog', $locale).'#blog'],
            'articleSection' => $post->kindLabel,
            'wordCount' => $words,
            'timeRequired' => 'PT'.$post->minutes.'M',
        ];

        if ($post->cover !== null) {
            $node['image'] = array_filter([
                '@type' => 'ImageObject',
                'url' => $post->cover,
                'width' => $post->coverWidth,
                'height' => $post->coverHeight,
            ]);
        }

        if ($mentions !== []) {
            $node['mentions'] = array_map(static fn (array $app): array => [
                '@type' => 'SoftwareApplication',
                'name' => $app['name'],
                'url' => $app['url'],
            ], $mentions);
        }

        return $node;
    }

    /**
     * The product itself, or one of its apps.
     *
     * @param  list<string>  $features
     * @param  array<string, mixed>|null  $offers
     * @return array<string, mixed>
     */
    public static function software(string $url, string $name, string $description, string $locale, array $features = [], ?array $offers = null): array
    {
        $node = [
            '@type' => 'SoftwareApplication',
            '@id' => $url.'#software',
            'name' => $name,
            'url' => $url,
            'description' => $description,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web browser',
            'inLanguage' => ['en', 'bn'],
            'publisher' => ['@id' => self::organizationId()],
        ];

        if ($features !== []) {
            $node['featureList'] = $features;
        }

        if ($offers !== null) {
            $node['offers'] = $offers;
        }

        return $node;
    }

    /**
     * Offers for the plans on sale, or null when nothing is.
     *
     * @param  list<array{amount: int, currency: string}>  $prices  monthly prices in minor units
     * @return array<string, mixed>|null
     */
    public static function aggregateOffer(array $prices, string $url): ?array
    {
        if ($prices === []) {
            return null;
        }

        $amounts = array_column($prices, 'amount');
        $currency = $prices[0]['currency'];

        return [
            '@type' => 'AggregateOffer',
            'priceCurrency' => $currency,
            'lowPrice' => self::decimal(min($amounts), $currency),
            'highPrice' => self::decimal(max($amounts), $currency),
            'offerCount' => count($prices),
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function offer(int $amount, string $currency, string $url): array
    {
        return [
            '@type' => 'Offer',
            'price' => self::decimal($amount, $currency),
            'priceCurrency' => $currency,
            'url' => $url,
        ];
    }

    private static function decimal(int $amount, string $currency): string
    {
        return Money::decimal($amount, $currency);
    }
}

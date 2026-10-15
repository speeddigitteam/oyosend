<?php

declare(strict_types=1);

namespace App\Core\Website;

/**
 * Every website page exists once per language at its own address: English at
 * the root (`/pricing`), Bengali under `/bn` (`/bn/pricing`). Search engines
 * index each language separately and learn from `hreflang` that they are the
 * same page.
 */
final class SiteUrls
{
    /** @var list<string> */
    public const array LOCALES = ['en', 'bn'];

    public const string DEFAULT_LOCALE = 'en';

    /**
     * The pages that exist in both languages, with no parameter but `app`; and the blog, whose articles (`post`) each
     * exist in the languages they are written in.
     *
     * @var list<string>
     */
    public const array PAGES = ['home', 'apps', 'app', 'pricing', 'security', 'about', 'contact', 'faq', 'signin', 'privacy', 'terms', 'refund', 'blog', 'post', 'blog_feed'];

    public static function routeName(string $page, string $locale): string
    {
        if ($locale === self::DEFAULT_LOCALE) {
            return $page === 'home' ? 'central.home' : "central.site.{$page}";
        }

        return "central.site.{$locale}.{$page}";
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function to(string $page, ?string $locale = null, array $parameters = []): string
    {
        return route(self::routeName($page, $locale ?? app()->getLocale()), $parameters);
    }

    /**
     * The same page in every language.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, string> locale => absolute URL
     */
    public static function alternates(string $page, array $parameters = []): array
    {
        $urls = [];

        foreach (self::LOCALES as $locale) {
            $urls[$locale] = self::to($page, $locale, $parameters);
        }

        return $urls;
    }

    /**
     * The other language of the page being shown, for the language switch.
     */
    public static function otherLocale(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' ? 'en' : 'bn';
    }
}

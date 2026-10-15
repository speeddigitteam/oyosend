<?php

declare(strict_types=1);

namespace App\Core\Website;

/**
 * What a website page tells search engines, answer engines and link previews
 * about itself: the head tags and the structured data.
 */
final readonly class PageMeta
{
    /**
     * @param  array<string, string>  $alternates  locale => URL of the same page, only the languages it exists in
     * @param  list<array<string, mixed>>  $schema  JSON-LD nodes for the page's @graph
     * @param  array<string, string>  $fallbacks  locale => where the language switch goes when the page is not in it
     * @param  array{published?: string, modified?: string, section?: string}  $article  for `og:type` article
     * @param  array{url: string, width: int|null, height: int|null, alt: string}|null  $picture  the page's own share image
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public array $alternates,
        public string $locale,
        public array $schema = [],
        public bool $index = true,
        public string $type = 'website',
        public array $fallbacks = [],
        public array $article = [],
        public ?array $picture = null,
        public ?string $feed = null,
    ) {}

    /**
     * The same page with some of its facts changed.
     *
     * @param  array<string, mixed>  $changes  constructor argument => value
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function image(): string
    {
        return $this->picture['url'] ?? asset('images/oyosend-og.jpg');
    }

    public function imageWidth(): ?int
    {
        return $this->picture === null ? 1200 : $this->picture['width'];
    }

    public function imageHeight(): ?int
    {
        return $this->picture === null ? 630 : $this->picture['height'];
    }

    public function imageAlt(): string
    {
        $alt = $this->picture['alt'] ?? '';

        return $alt !== '' ? $alt : __('site.meta.tagline');
    }

    /** Where "read this in the other language" goes: the same page, or the nearest page that exists in it. */
    public function languageLink(string $locale): string
    {
        return $this->alternates[$locale] ?? $this->fallbacks[$locale] ?? SiteUrls::to('home', $locale);
    }

    /** The page for readers of any other language: English when it exists in English. */
    public function defaultAlternate(): string
    {
        return $this->alternates[SiteUrls::DEFAULT_LOCALE] ?? $this->canonical;
    }

    /** Open Graph locale names. */
    public function ogLocale(?string $locale = null): string
    {
        return ($locale ?? $this->locale) === 'bn' ? 'bn_BD' : 'en_US';
    }

    /**
     * The page's structured data as one JSON-LD document, safe inside a script element.
     */
    public function jsonLd(): string
    {
        return (string) json_encode(
            ['@context' => 'https://schema.org', '@graph' => $this->schema],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
    }
}

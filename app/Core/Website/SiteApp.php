<?php

declare(strict_types=1);

namespace App\Core\Website;

/**
 * One app as the website presents it. Its words are in lang/{locale}/site_apps.php under `items.{module}`.
 */
final readonly class SiteApp
{
    public function __construct(
        public string $slug,
        public string $module,
        public string $category,
        public string $icon,
        public SitePrice $price,
    ) {}

    /**
     * A word of the app's copy in the current language: name, tagline, headline, answer...
     */
    public function text(string $key): string
    {
        return (string) __("site_apps.items.{$this->module}.{$key}");
    }

    /**
     * A list in the app's copy (features, audience, faq).
     *
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = __("site_apps.items.{$this->module}.{$key}");

        return is_array($value) ? array_values($value) : [];
    }
}

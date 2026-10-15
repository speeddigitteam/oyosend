<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Website\Models\WebsiteText;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The website's words as the operator changed them in the Super Admin panel (Website > Texts), over the defaults
 * in lang/{locale}/site*.php (docs/WEBSITE.md).
 *
 * Only words can change, never the shape: a key that is not a word in the file is refused, and so is a word that
 * drops a placeholder the page fills in (`:count`, `:price`). The translator reads the stored words through
 * {@see Support\WebsiteTextLoader}, so every template keeps calling `__()` as before. Platform data, cached in the
 * central cache and refreshed on every change.
 */
final class WebsiteTexts
{
    /** @var list<string> the translation groups the website owns */
    public const array GROUPS = ['site', 'site_pages', 'site_apps', 'site_legal'];

    /** Keys whose last segment is not a word but part of the page's structure. */
    private const array STRUCTURE = ['icon', 'id', 'tone'];

    private const string CACHE_KEY = 'website:texts';

    public function __construct(
        private readonly Cache $cache,
        private readonly Loader $files,
        private readonly SiteApps $apps,
    ) {}

    /**
     * Every stored word: locale => group => key within the group => word.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function overrides(): array
    {
        /** @var array<string, array<string, array<string, string>>> */
        return $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            $all = [];

            foreach (WebsiteText::query()->get(['locale', 'key', 'value']) as $row) {
                [$group, $item] = explode('.', $row->key, 2) + [1 => ''];
                $all[$row->locale][$group][$item] = $row->value;
            }

            return $all;
        });
    }

    /**
     * The parts of the website the editor offers, each a list of translation-key prefixes.
     *
     * @return array<string, list<string>>
     */
    public function sections(): array
    {
        $sections = [
            'home' => ['site.home'],
            'frame' => ['site.meta', 'site.nav', 'site.common', 'site.footer', 'site.signin', 'site.pricing', 'site.contact', 'site.llms'],
            'apps' => ['site_pages.apps', 'site_apps.categories'],
            'pricing' => ['site_pages.pricing'],
            'security' => ['site_pages.security'],
            'about' => ['site_pages.about'],
            'faq' => ['site_pages.faq'],
            'contact' => ['site_pages.contact'],
            'blog' => ['site_pages.blog'],
        ];

        foreach ($this->apps->all() as $app) {
            $sections["app.{$app->slug}"] = ["site_apps.items.{$app->module}"];
        }

        foreach (['privacy', 'terms', 'refund'] as $document) {
            $sections[$document] = ["site_legal.{$document}"];
        }

        $sections['privacy'] = [...$sections['privacy'], 'site_legal.updated', 'site_legal.updated_label', 'site_legal.contents', 'site_legal.questions'];

        return $sections;
    }

    /**
     * The words of one section in both languages: the file's default, and what the operator stored.
     *
     * @return list<array{key: string, default: array<string, string>, value: array<string, string|null>}>
     */
    public function fields(string $section): array
    {
        $prefixes = $this->sections()[$section] ?? [];
        $overrides = $this->overrides();
        $fields = [];

        foreach ($prefixes as $prefix) {
            foreach ($this->words('en', $prefix) as $key => $english) {
                $fields[] = [
                    'key' => $key,
                    'default' => ['en' => $english, 'bn' => $this->words('bn', $prefix)[$key] ?? ''],
                    'value' => [
                        'en' => $this->stored($overrides, 'en', $key),
                        'bn' => $this->stored($overrides, 'bn', $key),
                    ],
                ];
            }
        }

        return $fields;
    }

    /**
     * Store a section's words. A word equal to its default, or empty, is removed: the page shows the default.
     *
     * @param  array<string, array<string, string|null>>  $values  locale => key => word
     * @return array<string, array<string, string|null>> what changed, locale => key => new word (null = default)
     */
    public function save(string $section, array $values, ?string $editor): array
    {
        $fields = collect($this->fields($section))->keyBy('key');
        $changed = [];

        DB::connection((new WebsiteText)->getConnectionName())->transaction(function () use ($values, $fields, $editor, &$changed): void {
            foreach ($values as $locale => $words) {
                foreach ($words as $key => $word) {
                    $field = $fields->get($key);

                    if ($field === null || ! in_array($locale, SiteUrls::LOCALES, true)) {
                        continue;
                    }

                    $word = is_string($word) ? trim($word) : '';
                    $default = $field['default'][$locale];
                    $current = $field['value'][$locale];
                    $next = $word === '' || $word === $default ? null : $word;

                    if ($next === $current) {
                        continue;
                    }

                    if ($next === null) {
                        WebsiteText::query()->where('locale', $locale)->where('key', $key)->delete();
                    } else {
                        WebsiteText::query()->updateOrCreate(['locale' => $locale, 'key' => $key], ['value' => $next, 'updated_by' => $editor]);
                    }

                    $changed[$locale][$key] = $next;
                }
            }
        });

        $this->flush();

        return $changed;
    }

    /**
     * Back to the file's words for a whole section.
     *
     * @return int how many stored words were removed
     */
    public function reset(string $section): int
    {
        $keys = array_column($this->fields($section), 'key');
        $removed = $keys === [] ? 0 : WebsiteText::query()->whereIn('key', $keys)->delete();

        $this->flush();

        return (int) $removed;
    }

    /**
     * The placeholders a word must keep (`:count`, `:price`), so the page can still fill them in.
     *
     * @return list<string>
     */
    public static function placeholders(string $word): array
    {
        preg_match_all('/:[a-z_]+/i', $word, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);

        // The translator keeps the groups it loaded for the life of the process; a long-lived one must load again.
        if (app()->resolved('translator')) {
            app('translator')->setLoaded([]);
        }
    }

    /**
     * The file's words under a prefix, as full translation keys, leaving out structure (icons, anchors).
     *
     * @return array<string, string>
     */
    private function words(string $locale, string $prefix): array
    {
        [$group, $path] = explode('.', $prefix, 2) + [1 => ''];
        $lines = $this->files->load($locale, $group, '*');
        $value = $path === '' ? $lines : Arr::get($lines, $path);

        if (is_string($value)) {
            return [$prefix => $value];
        }

        $words = [];

        foreach (Arr::dot(is_array($value) ? $value : []) as $key => $word) {
            $last = (string) Str::afterLast((string) $key, '.');

            if (is_string($word) && ! in_array($last, self::STRUCTURE, true)) {
                $words["{$prefix}.{$key}"] = $word;
            }
        }

        return $words;
    }

    /**
     * @param  array<string, array<string, array<string, string>>>  $overrides
     */
    private function stored(array $overrides, string $locale, string $key): ?string
    {
        [$group, $item] = explode('.', $key, 2) + [1 => ''];

        return $overrides[$locale][$group][$item] ?? null;
    }
}

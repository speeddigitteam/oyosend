<?php

declare(strict_types=1);

namespace App\Core\Website\Support;

use App\Core\Website\WebsiteTexts;
use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Loads translation files as usual and lays the operator's changed website words over the website's groups
 * (`site`, `site_pages`, `site_apps`, `site_legal`), so the website's templates keep calling `__()` unchanged.
 * Any other group, and any key that is not already a word in the file, is left as the file has it.
 */
final readonly class WebsiteTextLoader implements Loader
{
    /**
     * @param  Closure(): array<string, array<string, array<string, string>>>  $overrides  locale => group => key => word
     */
    public function __construct(
        private Loader $files,
        private Closure $overrides,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->files->load($locale, $group, $namespace);

        if (($namespace !== null && $namespace !== '*') || ! in_array($group, WebsiteTexts::GROUPS, true)) {
            return $lines;
        }

        try {
            $words = ($this->overrides)()[$locale][$group] ?? [];
        } catch (Throwable) {
            // No database yet (a fresh install, a migration): the file's words.
            return $lines;
        }

        foreach ($words as $key => $word) {
            if (is_string(Arr::get($lines, $key))) {
                Arr::set($lines, $key, $word);
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->files->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return $this->files->namespaces();
    }

    /** The loader underneath: the files alone, for showing an operator the defaults. */
    public function files(): Loader
    {
        return $this->files;
    }
}

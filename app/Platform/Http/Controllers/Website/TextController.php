<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Toast;
use App\Core\Website\SiteApps;
use App\Core\Website\SiteUrls;
use App\Core\Website\WebsiteTexts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public website's words, changed from the Super Admin panel (docs/WEBSITE.md). Words only: the pages, their
 * sections and their questions stay as they are, so the structure search engines read never breaks. Each word can
 * go back to the website's own at any time.
 */
final class TextController
{
    private const int MAX_LENGTH = 5000;

    public function __construct(
        private readonly WebsiteTexts $texts,
        private readonly SiteApps $apps,
    ) {}

    public function index(Request $request): Response
    {
        $sections = $this->texts->sections();
        $section = (string) $request->query('section', 'home');
        abort_unless(isset($sections[$section]), 404);

        $prefixes = $sections[$section];

        return Inertia::render('Platform/Pages/Website/Texts', [
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'kind' => $this->kind($key),
                'name' => str_starts_with($key, 'app.') ? $this->appName($key) : null,
            ], array_keys($sections)),
            'section' => $section,
            'fields' => array_map(static function (array $field) use ($prefixes): array {
                $prefix = collect($prefixes)->first(fn (string $prefix): bool => str_starts_with($field['key'], $prefix.'.') || $field['key'] === $prefix) ?? '';

                return [
                    ...$field,
                    // Where the word sits within its section, e.g. "local.0.title".
                    'path' => ltrim(Str::after($field['key'], $prefix), '.') ?: Str::afterLast($field['key'], '.'),
                    'group' => Str::before(ltrim(Str::after($field['key'], $prefix), '.'), '.') ?: Str::afterLast($prefix, '.'),
                    'placeholders' => WebsiteTexts::placeholders($field['default']['en']),
                    'long' => mb_strlen($field['default']['en']) > 90,
                ];
            }, $this->texts->fields($section)),
            'preview' => $this->preview($section),
        ]);
    }

    public function update(Request $request, string $section, AuditLogger $audit): RedirectResponse
    {
        abort_unless(isset($this->texts->sections()[$section]), 404);

        // Checked by hand: the keys are translation keys with dots, which the validator would read as nesting.
        $values = $request->input('values');
        $values = is_array($values) ? $values : [];
        $fields = collect($this->texts->fields($section))->keyBy('key');
        $errors = [];
        $clean = [];

        foreach (SiteUrls::LOCALES as $locale) {
            foreach ((array) ($values[$locale] ?? []) as $key => $word) {
                $field = $fields->get((string) $key);

                if ($field === null || ($word !== null && ! is_string($word))) {
                    continue;
                }

                $word = trim((string) $word);

                if (mb_strlen($word) > self::MAX_LENGTH) {
                    $errors["{$locale}:{$key}"] = __('Keep this under :count characters.', ['count' => self::MAX_LENGTH]);

                    continue;
                }

                $missing = $word === '' ? [] : array_diff(WebsiteTexts::placeholders($field['default'][$locale]), WebsiteTexts::placeholders($word));

                if ($missing !== []) {
                    $errors["{$locale}:{$key}"] = __('Keep :placeholders in this text: the page fills them in.', ['placeholders' => implode(', ', $missing)]);

                    continue;
                }

                $clean[$locale][(string) $key] = $word;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($section, $clean, $request, $audit): void {
            $changed = $this->texts->save($section, $clean, $request->user('platform')?->getAuthIdentifier());

            if ($changed !== []) {
                $audit->record('platform.website.texts_updated', null, [
                    'section' => $section,
                    'keys' => array_map(array_keys(...), $changed),
                ]);
            }
        });

        Toast::success(__('Website words saved.'));

        return back();
    }

    public function reset(string $section, AuditLogger $audit): RedirectResponse
    {
        abort_unless(isset($this->texts->sections()[$section]), 404);

        DB::transaction(function () use ($section, $audit): void {
            $removed = $this->texts->reset($section);

            if ($removed > 0) {
                $audit->record('platform.website.texts_reset', null, ['section' => $section, 'removed' => $removed]);
            }
        });

        Toast::success(__('The website\'s own words are back.'));

        return back();
    }

    private function kind(string $section): string
    {
        return match (true) {
            str_starts_with($section, 'app.') => 'app',
            in_array($section, ['privacy', 'terms', 'refund'], true) => 'legal',
            default => 'page',
        };
    }

    private function appName(string $section): string
    {
        $app = $this->apps->find(Str::after($section, 'app.'));

        return $app === null ? $section : $app->text('name');
    }

    /** Where the section can be seen on the website, in English. */
    private function preview(string $section): string
    {
        return match (true) {
            $section === 'frame', $section === 'home' => SiteUrls::to('home', 'en'),
            str_starts_with($section, 'app.') => SiteUrls::to('app', 'en', ['app' => Str::after($section, 'app.')]),
            default => SiteUrls::to($section, 'en'),
        };
    }
}

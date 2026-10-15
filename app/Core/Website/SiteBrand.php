<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\Settings\PlatformSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The logo and favicon an operator uploaded in the Super Admin panel (Website > Company), used by the website and
 * every area of the app; without them the built-in Oyosend mark and favicon are used.
 *
 * Platform files, not a tenant's: kept on `site.brand.disk` under platform/brand/, named after their content (so a
 * browser may cache one for good), and served by the central site at /brand/{file}. Raster images only: an SVG can
 * carry script.
 */
final readonly class SiteBrand
{
    /** @var list<string> */
    public const array ASSETS = ['logo', 'logo_dark', 'favicon'];

    /** The file names this class writes: what /brand/{file} answers for. */
    public const string FILE_PATTERN = '(logo|logo_dark|favicon)-[a-f0-9]{16}\.(png|jpg|webp|ico)';

    private const string DIRECTORY = 'platform/brand';

    /** @var array<string, string> */
    private const array TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];

    public function __construct(private PlatformSettings $settings) {}

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('site.brand.disk', 'local'));
    }

    public static function path(string $file): string
    {
        return self::DIRECTORY.'/'.$file;
    }

    public static function type(string $file): string
    {
        return self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    /** The stored file name of an asset, or null when the built-in one is used. */
    public function file(string $asset): ?string
    {
        $file = $this->settings->get("website.{$asset}");

        return is_string($file) && preg_match('/^'.self::FILE_PATTERN.'$/', $file) === 1 ? $file : null;
    }

    /** Where the uploaded image can be loaded, on the central site, or null for the built-in one. */
    public function url(string $asset): ?string
    {
        $file = $this->file($asset);

        return $file === null ? null : route('central.brand', ['file' => $file]);
    }

    /**
     * The favicon for a page head: the uploaded one, or the built-in SVG.
     *
     * @return array{href: string, type: string}
     */
    public function favicon(): array
    {
        $file = $this->file('favicon');

        return $file === null
            ? ['href' => '/favicon.svg', 'type' => 'image/svg+xml']
            : ['href' => (string) $this->url('favicon'), 'type' => self::type($file)];
    }

    /**
     * For the app's pages (Inertia's shared `app.brand`).
     *
     * @return array{logo: string|null, logoDark: string|null}
     */
    public function shared(): array
    {
        return ['logo' => $this->url('logo'), 'logoDark' => $this->url('logo_dark')];
    }

    /**
     * Keep an uploaded image as the asset, and let go of the one it replaces.
     */
    public function store(string $asset, UploadedFile $upload): string
    {
        $extension = match (strtolower((string) $upload->guessExtension())) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => 'ico',
        };

        $contents = (string) file_get_contents((string) $upload->getRealPath());
        $file = $asset.'-'.substr(hash('sha256', $contents), 0, 16).'.'.$extension;

        self::disk()->put(self::path($file), $contents);

        $previous = $this->file($asset);
        $this->settings->put(["website.{$asset}" => $file]);

        if ($previous !== null && $previous !== $file) {
            self::disk()->delete(self::path($previous));
        }

        return $file;
    }

    /**
     * Back to the built-in image.
     */
    public function remove(string $asset): void
    {
        $previous = $this->file($asset);
        $this->settings->put(["website.{$asset}" => null]);

        if ($previous !== null) {
            self::disk()->delete(self::path($previous));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Controllers;

use App\Core\Website\SiteBrand;
use Illuminate\Http\Response;

/**
 * Serves an uploaded logo or favicon (App\Core\Website\SiteBrand) from the central site, for the website and for
 * every workspace and the admin panel. Stateless; named after its content, so it is cached for good.
 */
final class BrandAssetController
{
    public function __invoke(string $file): Response
    {
        $disk = SiteBrand::disk();
        $path = SiteBrand::path($file);

        abort_unless(preg_match('/^'.SiteBrand::FILE_PATTERN.'$/', $file) === 1 && $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => SiteBrand::type($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An image, never a document: opened on its own it can run nothing.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // Loaded by the workspace and admin hosts too.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Website\Http\Controllers;

use App\Core\Website\Blog\BlogMedia;
use Illuminate\Http\Response;

/**
 * Serves a blog image (App\Core\Website\Blog\BlogMedia) from the central site. Stateless; named after its content,
 * so it is cached for good. The Super Admin panel's editor shows the same images from here.
 */
final class BlogMediaController
{
    public function __invoke(string $file): Response
    {
        $disk = BlogMedia::disk();
        $path = BlogMedia::path($file);

        abort_unless(BlogMedia::isFile($file) && $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => BlogMedia::type($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An image, never a document: opened on its own it can run nothing.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // Shown by the admin panel's editor too.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}

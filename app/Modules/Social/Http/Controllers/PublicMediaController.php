<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Support\PublicMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One media-library file behind a temporary signed URL ({@see PublicMediaUrls}),
 * for a platform that fetches media from a URL. Stateless (`tenant.callback`: no session, no cookies),
 * throttled, and every failure (no or bad signature, expired, issued by another workspace, unknown or
 * other-workspace id, file missing) is the same 404, so the route cannot be used to probe the library.
 */
final class PublicMediaController
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $media): StreamedResponse
    {
        abort_unless(URL::hasValidSignature($request, false), 404);
        abort_unless($request->query('w') === $this->context->require()->slug, 404);

        // Tenant-scoped: an id from another workspace is simply not found.
        $item = Media::query()->find($media);
        abort_if($item === null, 404);

        $disk = $this->storage->disk();
        $path = $this->storage->path($item->path);
        $stream = $disk->exists($path) ? $disk->readStream($path) : null;
        abort_unless(is_resource($stream), 404);

        $size = $disk->size($path);
        $name = preg_replace('/[^\x20-\x7E]/', '_', $item->name) ?? 'file';

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $item->mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'inline; filename="'.addcslashes($name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

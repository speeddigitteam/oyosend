<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Models\Media;
use Illuminate\Support\Facades\URL;

/**
 * Public, expiring URLs to one media-library file, for a platform whose API fetches the media itself
 * (Instagram's content publishing takes an `image_url` / `video_url`). Library files are private; this
 * URL is the only exception, and only for the few minutes a platform needs (ADR 0033).
 *
 * The URL is a Laravel temporary signed route on the workspace host. The signature is computed over the
 * path and query (not the host, so it survives proxies and ports) and the query names the workspace,
 * which the route compares with the host's workspace, so a URL is valid only on the workspace that
 * issued it and only for its one file. Anyone holding the URL can fetch that file until it expires.
 */
final class PublicMediaUrls
{
    public const string ROUTE = 'social.media.public';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
    ) {}

    /** Minutes a URL stays valid: `social.media.public_url_minutes`, kept between 5 minutes and one day. */
    public static function lifetimeMinutes(): int
    {
        return max(5, min(1440, (int) config('social.media.public_url_minutes', 120)));
    }

    public function for(Media $media): string
    {
        $tenant = $this->context->require();

        $relative = URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(self::lifetimeMinutes()),
            ['tenant' => $tenant->slug, 'media' => $media->id, 'w' => $tenant->slug],
            false,
        );

        return $this->hosts->url($tenant, $relative);
    }
}

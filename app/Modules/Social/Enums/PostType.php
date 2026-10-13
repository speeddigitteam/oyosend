<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

/**
 * What kind of item a post publishes. `post` is the classic text / link / single image post.
 * Whether a platform can publish a type is decided by the adapter, never assumed.
 */
enum PostType: string
{
    case Post = 'post';
    case Photos = 'photos';
    case Video = 'video';
    case Reel = 'reel';

    public function needsVideo(): bool
    {
        return $this === self::Video || $this === self::Reel;
    }
}

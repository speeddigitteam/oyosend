<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Enums\PostType;

/**
 * An adapter that publishes more than a plain post and therefore judges media itself. A platform
 * that does not implement this publishes `post` only, with the generic capability checks.
 */
interface PostTypeAware
{
    /** @return list<PostType> The types this adapter really publishes (right now, with this configuration). */
    public function postTypes(): array;

    /**
     * Problems with publishing `$media` as `$type`.
     *
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function checkPost(PostType $type, array $media): array;

    /**
     * Short compatibility facts for one library file, for the media tile and the composer.
     * Codes: photo_ok, video_ok, video_too_long, video_too_large, reel_ready, reel_too_long,
     * reel_too_short, reel_low_resolution, reel_check, unsupported. Tone: ok, warn, danger, muted.
     *
     * @return list<array{code: string, tone: string}>
     */
    public function mediaBadges(MediaInfo $media): array;
}

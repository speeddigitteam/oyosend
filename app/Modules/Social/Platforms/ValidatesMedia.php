<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * Optional: an adapter with platform-specific rules for the media (and text) of a post that the
 * generic {@see PlatformCapabilities} flags cannot express, for example an aspect ratio, a picture
 * size, a file type or a caption needed as a video title.
 *
 * It is asked for every post that targets the platform, whatever the post type, in addition to the
 * generic checks (and to {@see PostTypeAware::checkPost()} when the adapter implements that too), on
 * the composer's live check and again before a post is submitted, approved or scheduled. A blocking
 * item keeps the post from being scheduled; a non-blocking one is shown as a warning.
 *
 * It must be pure: no HTTP call, no storage read, no token. Messages are already translated (`__()`).
 */
interface ValidatesMedia
{
    /**
     * @param  list<MediaInfo>  $media  The post's files, in order (facts only, never the bytes).
     * @param  string  $text  The text as it will be published to this account (variant + hashtags).
     * @return list<array{blocking: bool, message: string}>
     */
    public function mediaIssues(array $media, string $text): array;
}

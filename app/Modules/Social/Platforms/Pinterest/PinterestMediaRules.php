<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Pinterest;

use App\Modules\Social\Platforms\MediaInfo;

/**
 * Pinterest's own rules for a Pin, beyond the generic capability flags, and how a post's text becomes a Pin's
 * title and description. Pure: no HTTP, storage or token. Limits come from `social.platforms.pinterest.limits`.
 *
 * A Pin is one of: one image; a carousel of 2 to `carousel_max` images; or one video, optionally with one
 * image that becomes its cover (without one, Pinterest takes a frame of the video).
 */
final class PinterestMediaRules
{
    public static function limit(string $name, mixed $default): mixed
    {
        return config('social.platforms.pinterest.limits.'.$name, $default);
    }

    public static function carouselMax(): int
    {
        return max(1, (int) self::limit('carousel_max', 5));
    }

    /** @return list<string> */
    public static function imageMimes(): array
    {
        return array_values(array_map('strval', (array) self::limit('image_mimes', ['image/jpeg', 'image/png'])));
    }

    /** @return list<string> */
    public static function videoMimes(): array
    {
        return array_values(array_map('strval', (array) self::limit('video_mimes', ['video/mp4', 'video/quicktime'])));
    }

    /** The Pin title: the first non-empty line of the text, cut to `title_max` characters; null when there is none. */
    public static function title(string $text): ?string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_substr($line, 0, max(1, (int) self::limit('title_max', 100)));
            }
        }

        return null;
    }

    /** The Pin description: the whole text (its length is checked by `maxText`); null when empty. */
    public static function description(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function check(array $media, string $text): array
    {
        $items = [];
        $videos = array_values(array_filter($media, static fn (MediaInfo $item): bool => $item->isVideo));
        $images = array_values(array_filter($media, static fn (MediaInfo $item): bool => ! $item->isVideo));

        if (count($videos) > 1) {
            $items[] = self::block(__('A Pinterest Pin carries one video. Remove the other videos or deselect the account.'));
        }

        if ($videos !== [] && count($images) > 1) {
            $items[] = self::block(__('A Pinterest video Pin can carry one photo, used as its cover. Remove the other photos or deselect the account.'));
        }

        $imageMimes = self::imageMimes();
        $imageMax = (int) self::limit('image_max_bytes', 20 * 1024 * 1024);

        foreach ($images as $image) {
            if (! in_array(strtolower($image->mime), $imageMimes, true)) {
                $items[] = self::block(__('Pinterest accepts JPEG and PNG images only.'));
            }

            if ($imageMax > 0 && $image->sizeBytes > $imageMax) {
                $items[] = self::block(__('An image is larger than Pinterest accepts (:max MB).', ['max' => (string) intdiv($imageMax, 1024 * 1024)]));
            }
        }

        foreach ($videos as $video) {
            if (! in_array(strtolower($video->mime), self::videoMimes(), true)) {
                $items[] = self::block(__('Pinterest accepts MP4 and MOV videos only.'));
            }
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $max = max(1, (int) self::limit('title_max', 100));

            if (mb_strlen($line) > $max) {
                $items[] = ['blocking' => false, 'message' => __('The first line becomes the Pin title, which Pinterest limits to :max characters; it will be shortened.', ['max' => (string) $max])];
            }

            break;
        }

        return array_values(array_unique($items, SORT_REGULAR));
    }

    /** @return array{blocking: bool, message: string} */
    private static function block(string $message): array
    {
        return ['blocking' => true, 'message' => $message];
    }
}

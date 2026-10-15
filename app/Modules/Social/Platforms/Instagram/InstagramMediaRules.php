<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Instagram;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\MediaInfo;

/**
 * Instagram content-publishing rules, driven by `config('social.platforms.instagram.limits')`: Meta's documented
 * requirements as of this build, not guesses. A limit the config does not carry is not enforced, and a file whose
 * size could not be read is allowed with a warning.
 *
 * The video size and length bounds are capability flags (checked generically by `PostContentRules`), so they are
 * not repeated here; only the media badges read them.
 */
final class InstagramMediaRules
{
    /**
     * What the chosen post type allows: a plain post is one photo; a carousel (`photos`) is up to `carousel_max`
     * photos and videos; a video or a Reel is exactly one video (Instagram publishes every video as a Reel).
     *
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function check(PostType $type, array $media): array
    {
        $videos = array_values(array_filter($media, static fn (MediaInfo $item): bool => $item->isVideo));
        $images = count($media) - count($videos);

        return match ($type) {
            PostType::Post => $videos !== []
                ? [$this->block(__('A video needs the Video or Reel post type.'))]
                : ($images > 1 ? [$this->block(__('A plain post takes one photo. Choose the Photos post type for several.'))] : []),
            PostType::Photos => count($media) > $this->carouselMax()
                ? [$this->block(__('Instagram takes at most :max photos and videos in one carousel.', ['max' => $this->carouselMax()]))]
                : [],
            PostType::Video, PostType::Reel => ($images > 0 || count($videos) !== 1)
                ? [$this->block($type === PostType::Reel
                    ? __('A Reel is exactly one video. Remove everything else.')
                    : __('A video post is exactly one video. Remove everything else.'))]
                : [],
        };
    }

    /**
     * File type, photo size and aspect ratio, and the number of hashtags, whatever the post type.
     *
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function issues(array $media, string $text): array
    {
        $out = [];

        foreach ($media as $item) {
            array_push($out, ...($item->isVideo ? $this->video($item) : $this->image($item)));
        }

        $maxTags = (int) $this->limit('hashtags_max', 0);

        if ($maxTags > 0 && self::hashtags($text) > $maxTags) {
            $out[] = $this->block(__('Instagram allows at most :max hashtags in a caption.', ['max' => $maxTags]));
        }

        // The same problem on several files is said once.
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * @return list<array{code: string, tone: string}>
     */
    public function badges(MediaInfo $media): array
    {
        if (! $media->isVideo) {
            $blocking = array_filter($this->image($media), static fn (array $item): bool => $item['blocking']);

            return [$blocking === [] ? ['code' => 'photo_ok', 'tone' => 'ok'] : ['code' => 'unsupported', 'tone' => 'muted']];
        }

        if ($this->video($media) !== []) {
            return [['code' => 'unsupported', 'tone' => 'muted']];
        }

        $maxBytes = (int) $this->limit('reel_max_bytes', 0);
        $min = (int) $this->limit('reel_min_seconds', 0);
        $max = (int) $this->limit('reel_max_seconds', 0);

        return [match (true) {
            $maxBytes > 0 && $media->sizeBytes > $maxBytes => ['code' => 'video_too_large', 'tone' => 'danger'],
            $media->durationMs === null => ['code' => 'reel_check', 'tone' => 'warn'],
            $max > 0 && $media->durationMs > $max * 1000 => ['code' => 'reel_too_long', 'tone' => 'danger'],
            $min > 0 && $media->durationMs < $min * 1000 => ['code' => 'reel_too_short', 'tone' => 'danger'],
            default => ['code' => 'reel_ready', 'tone' => 'ok'],
        }];
    }

    /** Hashtags as Instagram counts them: `#` followed by letters, digits or underscores, not inside a word. */
    public static function hashtags(string $text): int
    {
        return (int) preg_match_all('/(?<![\p{L}\p{N}_&#])#[\p{L}\p{N}_]+/u', $text);
    }

    /** @return list<array{blocking: bool, message: string}> */
    private function image(MediaInfo $media): array
    {
        $out = [];
        $mimes = array_values(array_filter((array) $this->limit('image_mimes', []), 'is_string'));
        $maxBytes = (int) $this->limit('image_max_bytes', 0);

        if ($mimes !== [] && ! in_array(strtolower($media->mime), $mimes, true)) {
            $out[] = $this->block($mimes === ['image/jpeg']
                ? __('Instagram publishes JPEG photos only. Replace the other photos with JPEG files.')
                : __('Instagram does not accept this photo format.'));
        }

        if ($maxBytes > 0 && $media->sizeBytes > $maxBytes) {
            $out[] = $this->block(__('A photo is larger than Instagram accepts (:max MB).', ['max' => (string) intdiv($maxBytes, 1024 * 1024)]));
        }

        $min = (float) $this->limit('image_min_ratio', 0);
        $max = (float) $this->limit('image_max_ratio', 0);

        if ($min <= 0 && $max <= 0) {
            return $out;
        }

        if ($media->width === null || $media->height === null || $media->height < 1) {
            $out[] = ['blocking' => false, 'message' => __('A photo\'s size could not be read, so Instagram\'s aspect ratio limits could not be checked.')];

            return $out;
        }

        $ratio = $media->width / $media->height;
        $bounds = ['min' => $this->ratio($min), 'max' => $this->ratio($max)];

        if ($min > 0 && $ratio < $min - 0.005) {
            $out[] = $this->block(__('A photo is taller than Instagram allows. Crop it so its width divided by its height is between :min and :max.', $bounds));
        } elseif ($max > 0 && $ratio > $max + 0.005) {
            $out[] = $this->block(__('A photo is wider than Instagram allows. Crop it so its width divided by its height is between :min and :max.', $bounds));
        }

        return $out;
    }

    /** @return list<array{blocking: bool, message: string}> */
    private function video(MediaInfo $media): array
    {
        $mimes = array_values(array_filter((array) $this->limit('video_mimes', []), 'is_string'));

        if ($mimes !== [] && ! in_array(strtolower($media->mime), $mimes, true)) {
            return [$this->block(__('Instagram publishes MP4 and MOV videos only.'))];
        }

        return [];
    }

    private function carouselMax(): int
    {
        return max(1, (int) $this->limit('carousel_max', 10));
    }

    private function ratio(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function limit(string $key, mixed $default): mixed
    {
        return config('social.platforms.instagram.limits.'.$key, $default);
    }

    /** @return array{blocking: bool, message: string} */
    private function block(string $message): array
    {
        return ['blocking' => true, 'message' => $message];
    }
}

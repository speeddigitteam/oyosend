<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\MediaInfo;

/**
 * Facebook Page media rules, driven by `config('social.platforms.facebook.limits')`. The numbers are
 * Meta's documented requirements as of this build and are not guessed: a limit the config does not
 * carry is not enforced, and a file whose metadata could not be read is allowed with a warning.
 */
final class FacebookMediaRules
{
    /**
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function check(PostType $type, array $media): array
    {
        $videos = array_values(array_filter($media, static fn (MediaInfo $item): bool => $item->isVideo));
        $images = array_values(array_filter($media, static fn (MediaInfo $item): bool => ! $item->isVideo));
        $out = [];

        switch ($type) {
            case PostType::Post:
                if ($videos !== []) {
                    $out[] = $this->block(__('A video needs the Video or Reel post type.'));
                } elseif (count($images) > 1) {
                    $out[] = $this->block(__('A plain post takes one photo. Choose the Photos post type for several.'));
                }
                break;

            case PostType::Photos:
                $max = max(1, (int) $this->limit('photos_max', 10));

                if ($videos !== []) {
                    $out[] = $this->block(__('A photo post cannot include a video. Choose the Video or Reel post type.'));
                }

                if ($images === []) {
                    $out[] = $this->block(__('Add at least one photo.'));
                }

                if (count($images) > $max) {
                    $out[] = $this->block(__('Facebook takes at most :max photos in one post.', ['max' => $max]));
                }
                break;

            case PostType::Video:
            case PostType::Reel:
                if ($images !== [] || count($videos) !== 1) {
                    $out[] = $this->block($type === PostType::Reel
                        ? __('A Reel is exactly one video. Remove everything else.')
                        : __('A video post is exactly one video. Remove everything else.'));
                    break;
                }

                array_push($out, ...($type === PostType::Reel ? $this->reel($videos[0]) : $this->video($videos[0])));
                break;
        }

        return array_map(static fn (array $item): array => ['blocking' => $item['blocking'], 'message' => $item['message']], $out);
    }

    /**
     * @return list<array{code: string, tone: string}>
     */
    public function badges(MediaInfo $media): array
    {
        if (! $media->isVideo) {
            return [['code' => 'photo_ok', 'tone' => 'ok']];
        }

        $badges = [];
        $video = $this->video($media);
        $badges[] = $this->has($video, 'too_long') ? ['code' => 'video_too_long', 'tone' => 'danger']
            : ($this->has($video, 'too_large') ? ['code' => 'video_too_large', 'tone' => 'danger'] : ['code' => 'video_ok', 'tone' => 'ok']);

        $reel = $this->reel($media);

        if ($this->has($reel, 'too_long')) {
            $badges[] = ['code' => 'reel_too_long', 'tone' => 'danger'];
        } elseif ($this->has($reel, 'too_short')) {
            $badges[] = ['code' => 'reel_too_short', 'tone' => 'danger'];
        } elseif ($this->has($reel, 'low_resolution') || $this->has($reel, 'too_large')) {
            $badges[] = ['code' => 'reel_low_resolution', 'tone' => 'danger'];
        } elseif ($reel === []) {
            $badges[] = ['code' => 'reel_ready', 'tone' => 'ok'];
        } else {
            $badges[] = ['code' => 'reel_check', 'tone' => 'warn'];
        }

        return $badges;
    }

    /**
     * @param  list<array{blocking: bool, message: string, tag: string}>  $items
     */
    private function has(array $items, string $tag): bool
    {
        foreach ($items as $item) {
            if ($item['tag'] === $tag) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{blocking: bool, message: string, tag: string}> */
    private function video(MediaInfo $media): array
    {
        $out = [];
        $max = (int) $this->limit('video.max_seconds', 0);
        $maxBytes = (int) $this->limit('video.max_bytes', 0);

        if ($maxBytes > 0 && $media->sizeBytes > $maxBytes) {
            $out[] = $this->block(__('This video is larger than Facebook accepts.'), 'too_large');
        }

        if ($media->durationMs === null) {
            $out[] = $this->warn(__("We could not read this video's length. Facebook will check it when publishing."), 'unknown');
        } elseif ($max > 0 && $media->durationMs > $max * 1000) {
            $out[] = $this->block(__('This video is longer than :minutes minutes, the most Facebook accepts.', ['minutes' => intdiv($max, 60)]), 'too_long');
        }

        return $out;
    }

    /** @return list<array{blocking: bool, message: string, tag: string}> */
    private function reel(MediaInfo $media): array
    {
        $out = [];
        $min = (int) $this->limit('reel.min_seconds', 0);
        $max = (int) $this->limit('reel.max_seconds', 0);
        $maxBytes = (int) $this->limit('reel.max_bytes', 0);

        if ($maxBytes > 0 && $media->sizeBytes > $maxBytes) {
            $out[] = $this->block(__('This video is larger than a Reel can be.'), 'too_large');
        }

        if ($media->durationMs === null) {
            $out[] = $this->warn(__("We could not read this video's length and size. Facebook will check the Reel when publishing."), 'unknown');
        } else {
            if ($max > 0 && $media->durationMs > $max * 1000) {
                $out[] = $this->block(__('A Reel can be at most :seconds seconds; this video is longer.', ['seconds' => $max]), 'too_long');
            }

            if ($min > 0 && $media->durationMs < $min * 1000) {
                $out[] = $this->block(__('A Reel must be at least :seconds seconds; this video is shorter.', ['seconds' => $min]), 'too_short');
            }
        }

        if ($media->width !== null && $media->height !== null) {
            $minWidth = (int) $this->limit('reel.min_width', 0);
            $minHeight = (int) $this->limit('reel.min_height', 0);

            if (($minWidth > 0 && $media->width < $minWidth) || ($minHeight > 0 && $media->height < $minHeight)) {
                $out[] = $this->block(__('A Reel needs at least :width x :height pixels; this video is smaller.', [
                    'width' => $minWidth, 'height' => $minHeight,
                ]), 'low_resolution');
            }

            $aw = (int) $this->limit('reel.aspect_width', 0);
            $ah = (int) $this->limit('reel.aspect_height', 0);

            if ($aw > 0 && $ah > 0) {
                $want = $aw / $ah;
                $have = $media->width / max(1, $media->height);

                if (abs($have - $want) / $want > (float) $this->limit('reel.aspect_tolerance', 0.03)) {
                    $out[] = $this->warn(__('Reels are vertical (:w:h). This video has a different shape and may be cropped.', ['w' => $aw, 'h' => $ah]), 'aspect');
                }
            }
        }

        if ($media->mime === 'video/quicktime') {
            $out[] = $this->warn(__('MP4 is the format Facebook recommends for Reels; this file is QuickTime (MOV).'), 'format');
        }

        return $out;
    }

    private function limit(string $key, mixed $default): mixed
    {
        return config('social.platforms.facebook.limits.'.$key, $default);
    }

    /** @return array{blocking: bool, message: string, tag: string} */
    private function block(string $message, string $tag = 'rule'): array
    {
        return ['blocking' => true, 'message' => $message, 'tag' => $tag];
    }

    /** @return array{blocking: bool, message: string, tag: string} */
    private function warn(string $message, string $tag = 'note'): array
    {
        return ['blocking' => false, 'message' => $message, 'tag' => $tag];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PublishedPost;
use Illuminate\Http\Client\Response;

/**
 * Reads `GET /{video-id}?fields=status,permalink_url`. A video is `processing` until Facebook says
 * `status.video_status` is `ready` (and, for Reels, that the publishing phase completed); only then is it
 * published. An error status is a definite failure.
 */
final class FacebookVideoStatus
{
    /** @return array<string, string> */
    public static function query(): array
    {
        return ['fields' => 'status,permalink_url'];
    }

    /**
     * @return PublishedPost|null null while Facebook is still uploading / processing / publishing
     *
     * @throws PublishFailed when Facebook reports the video failed
     */
    public static function interpret(Response $response, string $videoId, string $reelUrlPrefix = ''): ?PublishedPost
    {
        $status = $response->json('status');

        if (! is_array($status)) {
            // A video node with no status object: not proven ready. Keep waiting; the timeout flags it.
            return null;
        }

        $video = (string) ($status['video_status'] ?? '');

        if ($video === 'error' || $video === 'expired') {
            throw PublishFailed::rejected(self::reason($status) ?? __('Facebook could not process this video.'), 'video_processing_failed');
        }

        foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $phase) {
            if (is_array($status[$phase] ?? null) && ($status[$phase]['status'] ?? null) === 'error') {
                throw PublishFailed::rejected(self::reason($status) ?? __('Facebook could not process this video.'), 'video_processing_failed');
            }
        }

        $publishing = is_array($status['publishing_phase'] ?? null) ? ($status['publishing_phase']['status'] ?? null) : null;

        if ($video !== 'ready' || ($publishing !== null && $publishing !== 'complete')) {
            return null;
        }

        $permalink = $response->json('permalink_url');
        $url = $reelUrlPrefix !== '' ? $reelUrlPrefix.$videoId
            : (is_string($permalink) && str_starts_with($permalink, '/') ? 'https://www.facebook.com'.$permalink : 'https://www.facebook.com/'.$videoId);

        return new PublishedPost($videoId, $url);
    }

    /** @param array<string, mixed> $status */
    private static function reason(array $status): ?string
    {
        foreach (['processing_phase', 'uploading_phase', 'publishing_phase'] as $phase) {
            $errors = is_array($status[$phase] ?? null) ? ($status[$phase]['errors'] ?? null) : null;

            if (is_array($errors) && is_array($errors[0] ?? null) && is_string($errors[0]['message'] ?? null)) {
                return FacebookGraphErrors::clean($errors[0]['message'], __('Facebook could not process this video.'));
            }
        }

        return null;
    }
}

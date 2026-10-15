<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\YouTube;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Maps a YouTube Data API / Google error response (`{"error": {"code", "message", "errors": [{"reason"}]}}`)
 * to a {@see PublishFailed}. Google's own text is shown only for request problems (an invalid title...);
 * authorisation, quota and limit problems get a translated explanation. No token or upload session URI
 * ever reaches a message: neither is part of an error response.
 */
final class YouTubeErrors
{
    public static function failure(Response $response): PublishFailed
    {
        $reason = self::reason($response);
        $message = self::message($response);
        $status = $response->status();

        if ($reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded') {
            return PublishFailed::rejected(
                __('The YouTube API daily quota of this installation is used up (each upload costs 1600 units). Publish again after the quota resets at midnight Pacific Time, or ask the operator to request more quota from Google.'),
                'youtube_quota_exceeded',
            );
        }

        if ($reason === 'uploadLimitExceeded') {
            return PublishFailed::rejected(
                __('This YouTube channel has reached its upload limit for now. Publish again later.'),
                'youtube_upload_limit',
            );
        }

        if ($reason === 'youtubeSignupRequired') {
            return PublishFailed::rejected(
                __('This Google account has no YouTube channel. Create the channel on YouTube, then connect it again.'),
                'youtube_channel_missing',
            );
        }

        if ($status === 429 || $reason === 'rateLimitExceeded' || $reason === 'userRateLimitExceeded') {
            return PublishFailed::rateLimited(__('YouTube asked to slow down. The upload continues later.'), self::retryAfter($response));
        }

        return match (true) {
            $status === 401 => PublishFailed::reauthorization(__('YouTube no longer accepts the stored authorisation. Connect the channel again.')),
            $status === 403 && in_array($reason, ['insufficientPermissions', 'forbidden', 'accessNotConfigured'], true) => $reason === 'accessNotConfigured'
                ? PublishFailed::rejected(__('The YouTube Data API is not enabled for the operator\'s Google project.'), 'youtube_api_disabled')
                : PublishFailed::reauthorization(__('YouTube denied the request: the channel did not grant the upload permission. Connect the channel again.')),
            $response->serverError() => PublishFailed::unknownOutcome($message ?? __('YouTube had a temporary problem.'), 'platform_error'),
            $status === 400 => PublishFailed::rejected($message ?? __('YouTube rejected the video details.'), 'youtube_invalid'),
            default => PublishFailed::rejected($message ?? __('YouTube rejected the request.'), 'youtube_'.$status),
        };
    }

    public static function reason(Response $response): ?string
    {
        $reason = $response->json('error.errors.0.reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private static function message(Response $response): ?string
    {
        $message = $response->json('error.message');

        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 300, '');
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, min(3600, (int) $header)) : null;
    }
}

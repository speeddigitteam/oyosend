<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Maps a TikTok API error (`{"error": {"code", "message", "log_id"}}`) or a failed post's `fail_reason`
 * to the outcome the publisher understands, in words a person can act on.
 *
 * Token and scope problems get our own explanation; other errors keep TikTok's own message, trimmed and
 * with any URL removed (an upload URL carries an upload token).
 */
final class TikTokErrors
{
    /** The token no longer works or lacks a granted scope: the account has to be connected again. */
    private const array REAUTHORIZE = [
        'access_token_invalid', 'scope_not_authorized', 'scope_permission_missed', 'token_not_authorized_for_specified_publish_id',
    ];

    public static function failure(Response $response): PublishFailed
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : '';

        if (in_array($code, self::REAUTHORIZE, true) || $response->status() === 401) {
            return PublishFailed::reauthorization(
                __('TikTok no longer accepts this account\'s authorisation, or a permission is missing. Connect the TikTok account again and allow video posting.'),
            );
        }

        if ($code === 'rate_limit_exceeded' || $response->status() === 429) {
            $header = $response->header('Retry-After');

            return PublishFailed::rateLimited(
                __('TikTok is limiting requests from this app for now. Publishing will try again shortly.'),
                is_numeric($header) ? max(1, min(3600, (int) $header)) : null,
            );
        }

        $known = self::reason($code);

        if ($known !== null) {
            return PublishFailed::rejected($known, 'tiktok_'.$code);
        }

        if ($response->serverError() || $code === 'internal_error') {
            return PublishFailed::unknownOutcome(
                __('TikTok did not confirm the request. Check the TikTok account before trying again.'),
                'platform_error',
            );
        }

        return PublishFailed::rejected(
            self::clean($response->json('error.message'), __('TikTok rejected the request.')),
            'tiktok_'.($code !== '' ? Str::limit($code, 60, '') : (string) $response->status()),
        );
    }

    /**
     * A post whose status is `FAILED`. Nothing was published.
     */
    public static function failedPost(mixed $reason): PublishFailed
    {
        $code = is_string($reason) ? $reason : '';

        if ($code === 'auth_removed') {
            return PublishFailed::reauthorization(
                __('The TikTok account removed this app\'s access, so the video was not posted. Connect the TikTok account again.'),
            );
        }

        return PublishFailed::rejected(
            self::reason($code) ?? __('TikTok could not post the video.'),
            'tiktok_'.($code !== '' ? Str::limit($code, 60, '') : 'failed'),
        );
    }

    /** Our explanation of a documented TikTok error code or failure reason; null when it has none. */
    public static function reason(string $code): ?string
    {
        return match ($code) {
            'spam_risk_too_many_posts' => __('This TikTok account has reached TikTok\'s daily limit of posts from apps. Try again tomorrow.'),
            'spam_risk_user_banned_from_posting' => __('TikTok does not allow this account to post right now.'),
            'spam_risk_too_many_pending_share' => __('This TikTok account has too many uploads still waiting to be processed. Try again later.'),
            'spam_risk_text', 'spam_risk' => __('TikTok flagged the post as a spam risk and did not post it.'),
            'reached_active_user_cap' => __('The TikTok app has reached its daily limit of creators who can post. Try again tomorrow.'),
            'unaudited_client_can_only_post_to_private_accounts' => __('TikTok has not audited this app yet, so it only accepts posts to private TikTok accounts. Make the TikTok account private, or wait until the app passes TikTok\'s audit.'),
            'privacy_level_option_mismatch' => __('TikTok did not accept the privacy level chosen for this account.'),
            'file_format_check_failed' => __('TikTok could not read the video format. Use an MP4, MOV or WebM file.'),
            'duration_check_failed' => __('TikTok did not accept the video length for this account.'),
            'frame_rate_check_failed' => __('TikTok did not accept the video frame rate (23 to 60 frames per second).'),
            'picture_size_check_failed' => __('TikTok did not accept the video picture size (360 to 4096 pixels per side).'),
            'publish_cancelled' => __('The post was cancelled on TikTok.'),
            default => null,
        };
    }

    public static function clean(mixed $message, string $fallback): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        $text = preg_replace('~[a-z][a-z0-9+.-]*://\S+~i', '[link]', $message) ?? '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return $text === '' ? $fallback : Str::limit($text, 300, '');
    }
}

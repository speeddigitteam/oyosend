<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Instagram;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Facebook\FacebookGraphErrors;
use Illuminate\Http\Client\Response;

/**
 * Maps an Instagram Graph API error. The classification (token, permission, throttle, server error) is the
 * Facebook one ({@see FacebookGraphErrors}), with the same error codes so the post page shows the same calm
 * "connect again" panel; the words name Instagram, and Instagram's own publishing limit (code 9) is a throttle.
 *
 * Every message is scrubbed of links and token-like strings: an error about a media download could otherwise
 * echo the signed media URL.
 */
final class InstagramGraphErrors
{
    /** Instagram's content-publishing limit (API-published posts per 24 hours, subcode 2207042). */
    private const int PUBLISHING_LIMIT_CODE = 9;

    public static function failure(Response $response): PublishFailed
    {
        $failure = FacebookGraphErrors::failure($response);
        $code = (int) $response->json('error.code', 0);

        if ($failure->errorCode === FacebookGraphErrors::TOKEN) {
            return new PublishFailed(
                __('Instagram no longer accepts this account connection. The access was removed or has expired. Connect the Instagram account again to continue.'),
                FacebookGraphErrors::TOKEN,
                reauthorize: true,
            );
        }

        if ($failure->errorCode === FacebookGraphErrors::PERMISSION) {
            return new PublishFailed(
                __('Instagram says this connection is missing a permission needed to publish. Connect the Instagram account again and approve every permission it asks for.'),
                FacebookGraphErrors::PERMISSION,
                reauthorize: true,
            );
        }

        if ($code === self::PUBLISHING_LIMIT_CODE) {
            return PublishFailed::rateLimited(
                __('This Instagram account reached its limit of posts published through the API in 24 hours. Publishing continues automatically later.'),
                3600,
            );
        }

        $message = $failure->getMessage();
        $userMessage = $response->json('error.error_user_msg');

        if (! $failure->retryable && ! $failure->outcomeUnknown && is_string($userMessage) && trim($userMessage) !== '') {
            // Instagram's publishing errors carry a readable explanation (aspect ratio, media download...).
            $message = FacebookGraphErrors::clean($userMessage, $message);
        } elseif ($message === __('Facebook rejected the request.')) {
            $message = __('Instagram rejected the request.');
        }

        return new PublishFailed(
            self::scrub($message),
            $failure->errorCode,
            retryable: $failure->retryable,
            retryAfterSeconds: $failure->retryAfterSeconds,
            outcomeUnknown: $failure->outcomeUnknown,
            reauthorize: $failure->reauthorize,
        );
    }

    /** Removes links (a signed media URL) and access-token-like strings from a platform message. */
    public static function scrub(string $message): string
    {
        $message = preg_replace('~\b(?:https?|ftp)://\S+~i', '[link]', $message) ?? '';
        $message = preg_replace('~\bEA[A-Za-z0-9]{16,}~', '[redacted]', $message) ?? '';

        return trim($message) === '' ? __('Instagram rejected the request.') : $message;
    }
}

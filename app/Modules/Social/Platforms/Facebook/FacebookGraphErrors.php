<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Maps a Graph API error to the outcome the publisher understands, in words a person can act on.
 *
 * Permission and token problems get our own calm explanation (the platform's text is technical and
 * may name internals); other errors keep the platform's own message, trimmed and token-free.
 */
final class FacebookGraphErrors
{
    /** Throttling: the request was not processed. */
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    /** The access token is no longer valid (expired, revoked, password changed, app removed). */
    private const array TOKEN_CODES = [102, 190, 459, 464, 467];

    /** The token is valid but lacks a permission or a role on the Page (3, 10, 200-299). */
    private const array PERMISSION_CODES = [3, 10];

    public const string PERMISSION = 'facebook_permission_missing';

    public const string TOKEN = 'reauthorization_required';

    public static function failure(Response $response): PublishFailed
    {
        $code = (int) $response->json('error.code', 0);
        $message = self::clean($response->json('error.message'), __('Facebook rejected the request.'));

        if (in_array($code, self::TOKEN_CODES, true) || $response->status() === 401) {
            return new PublishFailed(
                __('Facebook no longer accepts this Page connection. The access was removed or has expired. Connect the Page again to continue.'),
                self::TOKEN,
                reauthorize: true,
            );
        }

        if (in_array($code, self::PERMISSION_CODES, true) || ($code >= 200 && $code <= 299)) {
            return new PublishFailed(
                __('Facebook says this connection is missing a permission needed to publish. Connect the Page again and approve every permission it asks for.'),
                self::PERMISSION,
                reauthorize: true,
            );
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $response->status() === 429) {
            $header = $response->header('Retry-After');
            $after = is_numeric($header) ? max(1, min(3600, (int) $header)) : null;

            return PublishFailed::rateLimited($message, $after);
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return PublishFailed::unknownOutcome($message, 'platform_error');
        }

        return PublishFailed::rejected($message, 'facebook_'.($code > 0 ? $code : $response->status()));
    }

    public static function clean(mixed $message, string $fallback): string
    {
        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $message) ?? $fallback), 300, '');
    }
}

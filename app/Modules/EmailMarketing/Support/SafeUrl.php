<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

/**
 * The one definition of a link an email may carry: `https:` or `http:` with a host and no credentials, or a
 * `mailto:` address where the field allows it. `javascript:`, `data:`, `file:` and every other scheme are refused,
 * as are whitespace, control characters and angle brackets. The unsubscribe placeholder is the only variable a
 * link may contain.
 */
final class SafeUrl
{
    public const int MAX_LENGTH = 1000;

    public static function isAllowed(string $url, bool $allowMailto = false, bool $allowUnsubscribe = false): bool
    {
        $url = trim($url);

        if ($allowUnsubscribe && preg_match('/^\{\{\s*'.Variables::UNSUBSCRIBE.'\s*\}\}$/', $url) === 1) {
            return true;
        }

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F<>"\'\\\\`]/u', $url) === 1) {
            return false;
        }

        if (preg_match('~^mailto:~i', $url) === 1) {
            if (! $allowMailto) {
                return false;
            }

            $address = rawurldecode(explode('?', substr($url, 7), 2)[0]);

            return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $parts['host']) === 1;
    }

    /** Only `https:` (an image on a plain-http page would be mixed content in most clients). */
    public static function isSecureImage(string $url): bool
    {
        return self::isAllowed($url) && str_starts_with(strtolower(trim($url)), 'https://');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/**
 * The website chat visitor's credential: 32 random bytes, base64url without padding (43 characters). It is handed to
 * the visitor once, when the conversation starts, travels in the {@see HEADER} request header and is stored only as
 * its SHA-256. It reaches exactly one conversation of exactly one workspace.
 */
final class VisitorToken
{
    public const string HEADER = 'X-Chat-Visitor';

    private const string PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public static function issue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function wellFormed(#[\SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && preg_match(self::PATTERN, $token) === 1;
    }
}

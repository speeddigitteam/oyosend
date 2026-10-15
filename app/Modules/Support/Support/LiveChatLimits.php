<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The abuse limits of the public website chat (docs/SUPPORT.md section 15). Every public route also carries the
 * `tenant.callback` group throttle (60 requests a minute per address and workspace).
 *
 * Rate-limit keys hold a keyed hash of the visitor's address and the SHA-256 of their token, never either in the
 * clear, and the cache is per workspace, so every count is per workspace.
 */
final class LiveChatLimits
{
    public const int MAX_TEXT = 2000;

    public const int MAX_NAME = 100;

    public const int MAX_EMAIL = 190;

    public const int MAX_PAGE_URL = 500;

    public const int MAX_ORIGINS = 10;

    /** Messages one conversation can hold, both directions together. */
    public const int MAX_MESSAGES = 1000;

    /** Conversations one address may start in an hour (successful starts only). */
    public const int STARTS_PER_ADDRESS_PER_HOUR = 5;

    /** Conversations a whole workspace accepts in an hour, against spam spread over many addresses. */
    public const int STARTS_PER_WORKSPACE_PER_HOUR = 300;

    /** Attempts to start a conversation per address per minute, valid or not. */
    public const int START_ATTEMPTS_PER_MINUTE = 10;

    public const int SENDS_PER_TOKEN_PER_MINUTE = 20;

    public const int SENDS_PER_ADDRESS_PER_MINUTE = 40;

    /** The frame polls every 5 seconds while open (12 a minute). */
    public const int POLLS_PER_TOKEN_PER_MINUTE = 30;

    /** Messages one poll returns at most. */
    public const int POLL_BATCH = 50;

    public const string START = 'support-chat-start';

    public const string SEND = 'support-chat-send';

    public const string POLL = 'support-chat-poll';

    public static function register(): void
    {
        RateLimiter::for(self::START, static fn (Request $request): Limit => Limit::perMinute(self::START_ATTEMPTS_PER_MINUTE)
            ->by('address:'.self::addressKey($request)));

        RateLimiter::for(self::SEND, static fn (Request $request): array => [
            Limit::perMinute(self::SENDS_PER_TOKEN_PER_MINUTE)->by('token:'.self::tokenKey($request)),
            Limit::perMinute(self::SENDS_PER_ADDRESS_PER_MINUTE)->by('address:'.self::addressKey($request)),
        ]);

        RateLimiter::for(self::POLL, static fn (Request $request): Limit => Limit::perMinute(self::POLLS_PER_TOKEN_PER_MINUTE)
            ->by('token:'.self::tokenKey($request)));
    }

    /** The visitor's address as an HMAC with the application key: no rate-limit key holds an IP address. */
    public static function addressKey(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }

    /** The SHA-256 of the visitor token, or the address key when the request carries none. */
    public static function tokenKey(Request $request): string
    {
        $token = $request->header(VisitorToken::HEADER);

        return VisitorToken::wellFormed($token) ? VisitorToken::hash((string) $token) : 'none:'.self::addressKey($request);
    }
}

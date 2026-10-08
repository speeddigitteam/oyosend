<?php

declare(strict_types=1);

namespace App\Core\Support;

use Inertia\Inertia;

/**
 * Queues a toast for the next page. Uses Inertia flash data, so it is shown
 * once and never restored from browser history.
 */
final class Toast
{
    public static function success(string $message): void
    {
        self::push('success', $message);
    }

    public static function info(string $message): void
    {
        self::push('info', $message);
    }

    public static function warning(string $message): void
    {
        self::push('warning', $message);
    }

    public static function error(string $message): void
    {
        self::push('error', $message);
    }

    private static function push(string $type, string $message): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}

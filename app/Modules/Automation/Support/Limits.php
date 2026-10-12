<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

/** Reads the engine's hard limits (config/automation.php) as typed integers. */
final class Limits
{
    public static function int(string $key): int
    {
        return (int) config('automation.limits.'.$key);
    }
}

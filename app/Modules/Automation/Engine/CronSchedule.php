<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

/** Cron arithmetic in the workspace's timezone. Times are stored and compared in UTC. */
final class CronSchedule
{
    public static function isValid(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    /**
     * The next occurrence strictly after `$after`, as a UTC instant, or null when the expression is invalid.
     */
    public static function next(string $expression, string $timezone, CarbonImmutable $after): ?CarbonImmutable
    {
        if (! self::isValid($expression)) {
            return null;
        }

        try {
            $local = $after->setTimezone($timezone);
            $next = (new CronExpression($expression))->getNextRunDate($local, 0, false, $timezone);

            return CarbonImmutable::instance($next)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}

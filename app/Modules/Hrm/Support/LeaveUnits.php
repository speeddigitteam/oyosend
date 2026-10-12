<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Support;

/**
 * Leave is counted in integer units so that half days are exact: one working day
 * is {@see self::PER_DAY} units and a half day is one. There is no float anywhere.
 */
final class LeaveUnits
{
    public const int PER_DAY = 2;

    /** Units as a decimal string of days: 3 => "1.5", 4 => "2". For display only. */
    public static function toDays(int $units): string
    {
        $whole = intdiv(abs($units), self::PER_DAY);
        $half = abs($units) % self::PER_DAY === 1;
        $text = $whole.($half ? '.5' : '');

        return $units < 0 ? '-'.$text : $text;
    }
}

<?php

declare(strict_types=1);

use App\Modules\Support\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

/** Monday to Friday 09:00-17:00 in Dhaka (UTC+6, no daylight saving). */
$office = fn (array $holidays = []): BusinessCalendar => BusinessCalendar::make(
    'Asia/Dhaka',
    array_fill_keys([1, 2, 3, 4, 5], [['09:00', '17:00']]),
    $holidays,
);

$dhaka = fn (string $local): CarbonImmutable => CarbonImmutable::parse($local, 'Asia/Dhaka');

it('adds working minutes inside a single window', function () use ($office, $dhaka) {
    $due = $office()->addMinutes($dhaka('2026-03-09 10:00'), 90);

    expect($due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-09 11:30')
        ->and($due->utcOffset())->toBe(0);
});

it('rolls over to the next working morning after closing time', function () use ($office, $dhaka) {
    // Monday 16:30 + 60 minutes: 30 today, 30 on Tuesday morning.
    $due = $office()->addMinutes($dhaka('2026-03-09 16:30'), 60);

    expect($due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-10 09:30');
});

it('starts the clock at opening time when a request arrives outside hours', function () use ($office, $dhaka) {
    // Saturday 11:00 + 30 minutes lands on Monday 09:30.
    $due = $office()->addMinutes($dhaka('2026-03-07 11:00'), 30);

    expect($due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-09 09:30');
});

it('skips weekends in a long span', function () use ($office, $dhaka) {
    // Friday 16:00 + 16 hours = 1 hour Friday, then Monday and Tuesday full days minus 1 hour... 960 min.
    $due = $office()->addMinutes($dhaka('2026-03-06 16:00'), 960);

    // 60 (Fri) + 480 (Mon) + 420 of Tuesday = Tuesday 16:00.
    expect($due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-10 16:00');
});

it('treats holidays as closed days', function () use ($office, $dhaka) {
    $due = $office(['2026-03-10'])->addMinutes($dhaka('2026-03-09 16:30'), 60);

    expect($due->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-11 09:30');
});

it('supports several windows per day', function () use ($dhaka) {
    $split = BusinessCalendar::make('Asia/Dhaka', [1 => [['14:00', '18:00'], ['09:00', '12:00']]]);

    // Monday 11:00 + 120: 60 until 12:00, then the afternoon window from 14:00 gives 14:00 -> 15:00.
    expect($split->addMinutes($dhaka('2026-03-09 11:00'), 120)->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('15:00');
});

it('counts working minutes between two instants', function () use ($office, $dhaka) {
    $calendar = $office();

    expect($calendar->minutesBetween($dhaka('2026-03-09 16:00'), $dhaka('2026-03-10 10:00')))->toBe(120)
        ->and($calendar->minutesBetween($dhaka('2026-03-07 08:00'), $dhaka('2026-03-08 20:00')))->toBe(0)
        ->and($calendar->minutesBetween($dhaka('2026-03-10 12:00'), $dhaka('2026-03-09 12:00')))->toBe(0)
        ->and($calendar->minutesBetween($dhaka('2026-03-09 00:00'), $dhaka('2026-03-13 23:59')))->toBe(5 * 480);
});

it('is the inverse of addMinutes inside working time', function () use ($office, $dhaka) {
    $calendar = $office();
    $from = $dhaka('2026-03-09 13:17');

    foreach ([1, 59, 240, 480, 1000, 2400] as $minutes) {
        expect($calendar->minutesBetween($from, $calendar->addMinutes($from, $minutes)))->toBe($minutes);
    }
});

it('reads local hours in the calendar timezone, not the server timezone', function () {
    // The same wall-clock window in New York is a different instant than in Dhaka.
    $newYork = BusinessCalendar::make('America/New_York', [1 => [['09:00', '17:00']]]);
    $due = $newYork->addMinutes(CarbonImmutable::parse('2026-03-09 13:00', 'UTC'), 60);

    // 13:00 UTC is 09:00 EDT (daylight time began on 2026-03-08): opening time, so 10:00 EDT.
    expect($due->setTimezone('America/New_York')->format('H:i'))->toBe('10:00');
});

it('honours a daylight-saving jump when counting a day', function () {
    $newYork = BusinessCalendar::make('America/New_York', [7 => [['00:00', '24:00']], 1 => [['00:00', '24:00']]]);

    // Sunday 2026-03-08 has only 23 hours in New York.
    $from = CarbonImmutable::parse('2026-03-08 00:00', 'America/New_York');
    $to = CarbonImmutable::parse('2026-03-09 00:00', 'America/New_York');

    expect($newYork->minutesBetween($from, $to))->toBe(23 * 60);
});

it('counts every minute for an always-open calendar', function () use ($dhaka) {
    $always = BusinessCalendar::always('Asia/Dhaka');

    expect($always->addMinutes($dhaka('2026-03-07 23:30'), 90)->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-03-08 01:00')
        ->and($always->minutesBetween($dhaka('2026-03-07 00:00'), $dhaka('2026-03-08 00:00')))->toBe(1440)
        ->and($always->isWorking($dhaka('2026-03-08 03:00')))->toBeTrue();
});

it('reports whether an instant is within working time', function () use ($office, $dhaka) {
    $calendar = $office();

    expect($calendar->isWorking($dhaka('2026-03-09 09:00')))->toBeTrue()
        ->and($calendar->isWorking($dhaka('2026-03-09 17:00')))->toBeFalse()
        ->and($calendar->isWorking($dhaka('2026-03-08 10:00')))->toBeFalse();
});

it('returns the same instant for a zero or negative duration', function () use ($office, $dhaka) {
    $from = $dhaka('2026-03-09 18:00');

    expect($office()->addMinutes($from, 0)->equalTo($from))->toBeTrue()
        ->and($office()->addMinutes($from, -5)->equalTo($from))->toBeTrue();
});

it('validates and normalizes weekly hours', function () {
    expect(BusinessCalendar::normalizeHours([1 => [['13:00', '17:00'], ['09:00', '12:00']], '2' => [['00:00', '24:00']]]))
        ->toBe([1 => [['09:00', '12:00'], ['13:00', '17:00']], 2 => [['00:00', '24:00']]]);

    foreach ([
        [8 => [['09:00', '17:00']]],
        [1 => [['17:00', '09:00']]],
        [1 => [['09:00', '13:00'], ['12:00', '15:00']]],
        [1 => [['9am', '5pm']]],
        [],
        [1 => []],
    ] as $bad) {
        expect(fn () => BusinessCalendar::normalizeHours($bad))->toThrow(InvalidArgumentException::class);
    }
});

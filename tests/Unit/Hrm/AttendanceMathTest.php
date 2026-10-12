<?php

declare(strict_types=1);

use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\AttendanceMath;
use Carbon\CarbonImmutable;

$shift = fn (array $overrides = []): Shift => new Shift([
    'name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '18:00', 'break_minutes' => 60, 'late_grace_minutes' => 10,
    'early_grace_minutes' => 5, 'overtime_threshold_minutes' => 30, 'half_day_minutes' => null,
    'weekly_off_days' => null, 'is_default' => true, 'is_active' => true, ...$overrides,
]);

/** One entry; times are local Asia/Dhaka (UTC+6, no daylight saving). */
$day = function (string $in, ?string $out, ?Shift $shift, array $flags = []) {
    $entries = [['in' => CarbonImmutable::parse($in, 'Asia/Dhaka')->utc(), 'out' => $out === null ? null : CarbonImmutable::parse($out, 'Asia/Dhaka')->utc()]];

    return AttendanceMath::evaluate(
        $flags['date'] ?? '2026-03-02', $flags['tz'] ?? 'Asia/Dhaka', $shift, $flags['entries'] ?? $entries,
        $flags['holiday'] ?? false, $flags['off'] ?? false, $flags['leave'] ?? 0, 480,
    );
};

it('computes scheduled minutes from the shift span less the break', function () use ($shift) {
    expect($shift()->scheduledMinutes())->toBe(480)
        ->and($shift()->crossesMidnight())->toBeFalse()
        ->and($shift(['starts_at' => '22:00', 'ends_at' => '06:00', 'break_minutes' => 0])->scheduledMinutes())->toBe(480)
        ->and($shift(['starts_at' => '22:00', 'ends_at' => '06:00'])->crossesMidnight())->toBeTrue();
});

it('counts exact whole minutes and ignores seconds', function () use ($shift, $day) {
    $result = $day('2026-03-02 09:00:59', '2026-03-02 17:59:01', $shift(['break_minutes' => 0]));

    // 09:00 .. 17:59 once seconds are floored.
    expect($result['worked_minutes'])->toBe(539)
        ->and($result['status'])->toBe(DayStatus::Present);
});

it('treats the grace as free and counts the whole delay once it is exceeded', function () use ($shift, $day) {
    expect($day('2026-03-02 09:10', '2026-03-02 18:00', $shift())['late_minutes'])->toBe(0)
        ->and($day('2026-03-02 09:11', '2026-03-02 18:00', $shift())['late_minutes'])->toBe(11)
        ->and($day('2026-03-02 08:45', '2026-03-02 18:00', $shift())['late_minutes'])->toBe(0);
});

it('computes early leave against the shift end with its own grace', function () use ($shift, $day) {
    expect($day('2026-03-02 09:00', '2026-03-02 17:55', $shift())['early_leave_minutes'])->toBe(0)
        ->and($day('2026-03-02 09:00', '2026-03-02 17:54', $shift())['early_leave_minutes'])->toBe(6)
        ->and($day('2026-03-02 09:00', '2026-03-02 16:00', $shift())['early_leave_minutes'])->toBe(120);
});

it('counts overtime only once the threshold is met', function () use ($shift, $day) {
    expect($day('2026-03-02 09:00', '2026-03-02 18:29', $shift())['overtime_minutes'])->toBe(0)
        ->and($day('2026-03-02 09:00', '2026-03-02 18:30', $shift())['overtime_minutes'])->toBe(30)
        ->and($day('2026-03-02 09:00', '2026-03-02 20:15', $shift())['overtime_minutes'])->toBe(135);
});

it('deducts the break only from one continuous entry', function () use ($shift, $day) {
    $continuous = $day('2026-03-02 09:00', '2026-03-02 18:00', $shift());
    $split = $day('x', null, $shift(), ['entries' => [
        ['in' => CarbonImmutable::parse('2026-03-02 09:00', 'Asia/Dhaka')->utc(), 'out' => CarbonImmutable::parse('2026-03-02 13:00', 'Asia/Dhaka')->utc()],
        ['in' => CarbonImmutable::parse('2026-03-02 14:00', 'Asia/Dhaka')->utc(), 'out' => CarbonImmutable::parse('2026-03-02 18:00', 'Asia/Dhaka')->utc()],
    ]]);

    expect($continuous['worked_minutes'])->toBe(480)
        ->and($split['worked_minutes'])->toBe(480)
        ->and($split['entries_count'])->toBe(2);
});

it('merges overlapping entries instead of double counting', function () use ($shift, $day) {
    $result = $day('x', null, $shift(['break_minutes' => 0]), ['entries' => [
        ['in' => CarbonImmutable::parse('2026-03-02 09:00', 'Asia/Dhaka')->utc(), 'out' => CarbonImmutable::parse('2026-03-02 12:00', 'Asia/Dhaka')->utc()],
        ['in' => CarbonImmutable::parse('2026-03-02 11:00', 'Asia/Dhaka')->utc(), 'out' => CarbonImmutable::parse('2026-03-02 13:00', 'Asia/Dhaka')->utc()],
    ]]);

    expect($result['worked_minutes'])->toBe(240);
});

it('interprets the shift in the workspace timezone, not in UTC', function () use ($shift, $day) {
    // 03:20 UTC is 09:20 in Dhaka (late by 20) but 23:20 the evening before in New York.
    $dhaka = $day('2026-03-02 09:20', '2026-03-02 18:00', $shift());
    $newYork = AttendanceMath::evaluate(
        '2026-03-02', 'America/New_York', $shift(),
        [['in' => CarbonImmutable::parse('2026-03-02 03:20', 'UTC'), 'out' => CarbonImmutable::parse('2026-03-02 12:00', 'UTC')]],
        false, false, 0, 480,
    );

    expect($dhaka['late_minutes'])->toBe(20)
        // 09:00 in New York (EST, UTC-5) is 14:00 UTC; arriving at 03:20 UTC is hours early, not late.
        ->and($newYork['late_minutes'])->toBe(0);
});

it('handles a shift that ends after midnight', function () use ($shift) {
    $night = $shift(['starts_at' => '22:00', 'ends_at' => '06:00', 'break_minutes' => 0, 'overtime_threshold_minutes' => 0, 'late_grace_minutes' => 0, 'early_grace_minutes' => 0]);
    $result = AttendanceMath::evaluate(
        '2026-03-02', 'Asia/Dhaka', $night,
        [['in' => CarbonImmutable::parse('2026-03-02 22:05', 'Asia/Dhaka')->utc(), 'out' => CarbonImmutable::parse('2026-03-03 06:20', 'Asia/Dhaka')->utc()]],
        false, false, 0, 480,
    );

    expect($result['worked_minutes'])->toBe(495)
        ->and($result['late_minutes'])->toBe(5)
        ->and($result['overtime_minutes'])->toBe(20)
        ->and($result['early_leave_minutes'])->toBe(0);
});

it('classifies the day', function () use ($shift, $day) {
    expect($day('2026-03-02 09:00', '2026-03-02 18:00', $shift())['status'])->toBe(DayStatus::Present)
        ->and($day('2026-03-02 09:00', '2026-03-02 11:00', $shift())['status'])->toBe(DayStatus::HalfDay)
        ->and($day('x', null, $shift(), ['entries' => []])['status'])->toBe(DayStatus::Absent)
        ->and($day('2026-03-02 09:00', null, $shift())['status'])->toBe(DayStatus::Incomplete)
        ->and($day('x', null, $shift(), ['entries' => [], 'leave' => 2])['status'])->toBe(DayStatus::OnLeave)
        ->and($day('x', null, $shift(), ['entries' => [], 'holiday' => true])['status'])->toBe(DayStatus::Holiday)
        ->and($day('x', null, $shift(), ['entries' => [], 'off' => true])['status'])->toBe(DayStatus::WeeklyOff);
});

it('treats everything worked on a weekly off or holiday as overtime', function () use ($shift, $day) {
    $result = $day('2026-03-06 10:00', '2026-03-06 14:30', $shift(), ['off' => true, 'date' => '2026-03-06']);

    expect($result['scheduled_minutes'])->toBe(0)
        // 4h30 on one continuous entry, less the 60 minute break.
        ->and($result['worked_minutes'])->toBe(210)
        ->and($result['overtime_minutes'])->toBe(210)
        ->and($result['late_minutes'])->toBe(0)
        ->and($result['status'])->toBe(DayStatus::WeeklyOff);
});

it('uses the workspace work day when no shift applies', function () use ($day) {
    $result = $day('2026-03-02 09:00', '2026-03-02 17:00', null);

    expect($result['scheduled_minutes'])->toBe(480)
        ->and($result['worked_minutes'])->toBe(480)
        ->and($result['late_minutes'])->toBe(0);
});

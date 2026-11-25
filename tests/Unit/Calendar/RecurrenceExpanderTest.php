<?php

declare(strict_types=1);

use App\Modules\Calendar\Enums\CalendarView;
use App\Modules\Calendar\Enums\MonthlyMode;
use App\Modules\Calendar\Enums\RecurrenceFrequency;
use App\Modules\Calendar\Support\CalendarRange;
use App\Modules\Calendar\Support\RecurrenceExpander;
use App\Modules\Calendar\Support\RecurrenceRule;
use Carbon\CarbonImmutable;

function expand(string $start, RecurrenceRule $rule, string $from, string $to, string $zone = 'UTC', int $duration = 3600): array
{
    $series = CarbonImmutable::parse($start, $zone);

    return array_map(
        static fn (CarbonImmutable $occurrence): string => $occurrence->format('Y-m-d H:i'),
        (new RecurrenceExpander)->between($series, $rule, $duration, CarbonImmutable::parse($from, $zone), CarbonImmutable::parse($to, $zone)),
    );
}

it('repeats daily, every N days', function () {
    expect(expand('2026-10-01 09:00', new RecurrenceRule(RecurrenceFrequency::Daily), '2026-10-01', '2026-10-04'))
        ->toBe(['2026-10-01 09:00', '2026-10-02 09:00', '2026-10-03 09:00'])
        ->and(expand('2026-10-01 09:00', new RecurrenceRule(RecurrenceFrequency::Daily, 3), '2026-10-01', '2026-10-11'))
        ->toBe(['2026-10-01 09:00', '2026-10-04 09:00', '2026-10-07 09:00', '2026-10-10 09:00']);
});

it('repeats weekly on several weekdays, every other week', function () {
    // Thursday 1 Oct 2026; Mondays and Thursdays, every second week (weeks start on Monday).
    $rule = new RecurrenceRule(RecurrenceFrequency::Weekly, 2, [1, 4]);

    expect(expand('2026-10-01 10:00', $rule, '2026-09-28', '2026-10-27'))
        ->toBe(['2026-10-01 10:00', '2026-10-12 10:00', '2026-10-15 10:00', '2026-10-26 10:00']);
});

it('repeats monthly by date and skips months without that date', function () {
    expect(expand('2026-01-31 08:00', new RecurrenceRule(RecurrenceFrequency::Monthly), '2026-01-01', '2026-06-01'))
        ->toBe(['2026-01-31 08:00', '2026-03-31 08:00', '2026-05-31 08:00']);
});

it('repeats monthly on the nth and on the last weekday', function () {
    // Saturday 17 Oct 2026 is the third Saturday; Saturday 31 Oct 2026 the last.
    expect(expand('2026-10-17 09:00', new RecurrenceRule(RecurrenceFrequency::Monthly, monthly: MonthlyMode::Weekday), '2026-10-01', '2027-01-01'))
        ->toBe(['2026-10-17 09:00', '2026-11-21 09:00', '2026-12-19 09:00'])
        ->and(expand('2026-10-31 09:00', new RecurrenceRule(RecurrenceFrequency::Monthly, monthly: MonthlyMode::LastWeekday), '2026-10-01', '2027-01-01'))
        ->toBe(['2026-10-31 09:00', '2026-11-28 09:00', '2026-12-26 09:00']);
});

it('repeats yearly and keeps 29 February to leap years', function () {
    expect(expand('2024-02-29 00:00', new RecurrenceRule(RecurrenceFrequency::Yearly), '2024-01-01', '2033-01-01'))
        ->toBe(['2024-02-29 00:00', '2028-02-29 00:00', '2032-02-29 00:00']);
});

it('stops after a count or on an end date', function () {
    $expander = new RecurrenceExpander;
    $start = CarbonImmutable::parse('2026-10-01 09:00', 'UTC');

    expect(expand('2026-10-01 09:00', new RecurrenceRule(RecurrenceFrequency::Daily, count: 3), '2026-10-01', '2026-12-01'))
        ->toHaveCount(3)
        ->and(expand('2026-10-01 09:00', new RecurrenceRule(RecurrenceFrequency::Daily, until: '2026-10-05'), '2026-10-01', '2026-12-01'))
        ->toHaveCount(5)
        ->and($expander->lastStart($start, new RecurrenceRule(RecurrenceFrequency::Weekly, count: 4))?->format('Y-m-d'))->toBe('2026-10-22')
        ->and($expander->lastStart($start, new RecurrenceRule(RecurrenceFrequency::Weekly)))->toBeNull()
        ->and($expander->countBefore($start, new RecurrenceRule(RecurrenceFrequency::Daily), CarbonImmutable::parse('2026-10-04 09:00', 'UTC')))->toBe(3);
});

it('keeps the wall-clock time across a daylight-saving change', function () {
    // New York leaves summer time on 1 Nov 2026: 9:00 stays 9:00, its UTC instant moves an hour.
    $series = CarbonImmutable::parse('2026-10-30 09:00', 'America/New_York');
    $occurrences = (new RecurrenceExpander)->between($series, new RecurrenceRule(RecurrenceFrequency::Daily), 3600, $series, $series->addDays(4));

    expect(array_map(fn (CarbonImmutable $o): string => $o->format('H:i'), $occurrences))->toBe(['09:00', '09:00', '09:00', '09:00'])
        ->and($occurrences[0]->utc()->format('H:i'))->toBe('13:00')
        ->and($occurrences[3]->utc()->format('H:i'))->toBe('14:00');
});

it('finds occurrences far from the series start and ones that reach into the window', function () {
    expect(expand('2020-01-01 23:00', new RecurrenceRule(RecurrenceFrequency::Daily), '2026-10-17 00:00', '2026-10-17 01:00', duration: 7200))
        ->toBe(['2026-10-16 23:00'])
        ->and((new RecurrenceExpander)->occursAt(CarbonImmutable::parse('2026-10-01 09:00', 'UTC'), new RecurrenceRule(RecurrenceFrequency::Weekly), CarbonImmutable::parse('2026-10-15 09:00', 'UTC')))->toBeTrue()
        ->and((new RecurrenceExpander)->occursAt(CarbonImmutable::parse('2026-10-01 09:00', 'UTC'), new RecurrenceRule(RecurrenceFrequency::Weekly), CarbonImmutable::parse('2026-10-16 09:00', 'UTC')))->toBeFalse();
});

it('refuses rules it cannot honour', function (array $data) {
    RecurrenceRule::fromArray($data);
})->throws(InvalidArgumentException::class)->with([
    'zero interval' => [['frequency' => 'daily', 'interval' => 0]],
    'count and until' => [['frequency' => 'daily', 'count' => 2, 'until' => '2026-12-01']],
    'bad weekday' => [['frequency' => 'weekly', 'weekdays' => [7]]],
    'huge count' => [['frequency' => 'daily', 'count' => 5000]],
]);

it('computes the dates each view shows', function (CalendarView $view, string $anchor, int $weekStart, string $start, string $end) {
    $range = CalendarRange::for($view, $anchor, $weekStart);

    expect([$range->start, $range->end])->toBe([$start, $end]);
})->with([
    'day' => [CalendarView::Day, '2026-10-17', 0, '2026-10-17', '2026-10-18'],
    'four days' => [CalendarView::FourDays, '2026-10-17', 0, '2026-10-17', '2026-10-21'],
    'week from Sunday' => [CalendarView::Week, '2026-10-17', 0, '2026-10-11', '2026-10-18'],
    'week from Saturday' => [CalendarView::Week, '2026-10-17', 6, '2026-10-17', '2026-10-24'],
    'week from Monday' => [CalendarView::Week, '2026-10-17', 1, '2026-10-12', '2026-10-19'],
    'month' => [CalendarView::Month, '2026-10-17', 0, '2026-09-27', '2026-11-01'],
    'month of six weeks' => [CalendarView::Month, '2026-08-05', 0, '2026-07-26', '2026-09-06'],
    'year' => [CalendarView::Year, '2026-10-17', 0, '2026-01-01', '2027-01-01'],
    'schedule' => [CalendarView::Schedule, '2026-10-17', 0, '2026-10-17', '2026-11-17'],
]);

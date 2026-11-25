<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Models\CalendarEvent;

/**
 * Events on workspace calendars that start in the period, by calendar. Personal calendars are never counted: they
 * are private by person, and the report reader may not be allowed to see them.
 */
final class WorkspaceEventsReport implements ReportProvider
{
    public const string PERMISSION = 'calendar.events.view';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'calendar.workspace_events',
            module: 'calendar',
            label: 'Workspace calendar events',
            description: 'Events on workspace calendars that start in the period, by calendar. Repeating events count once, by their first date. Personal calendars are never counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('calendar', 'Calendar'),
                new ReportColumn('events', 'Events', ColumnType::Integer),
                new ReportColumn('all_day', 'All-day events', ColumnType::Integer),
                new ReportColumn('repeating', 'Repeating events', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = CalendarEvent::query()
            ->join('calendar_calendars', 'calendar_calendars.id', '=', 'calendar_events.calendar_id')
            ->whereNull('calendar_calendars.owner_id')
            ->whereNull('calendar_events.recurring_event_id')
            ->where('calendar_events.status', EventStatus::Confirmed->value)
            ->whereBetween('calendar_events.starts_at', [$context->fromAt, $context->toAt])
            ->groupBy('calendar_calendars.id', 'calendar_calendars.name')
            ->orderByDesc('events')->orderBy('calendar_calendars.name')
            ->select(['calendar_calendars.name as calendar'])
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(CASE WHEN calendar_events.is_all_day = 1 THEN 1 ELSE 0 END) as all_day')
            ->selectRaw('SUM(CASE WHEN calendar_events.recurrence IS NOT NULL THEN 1 ELSE 0 END) as repeating')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static fn (object $row): array => [
            'calendar' => (string) $row->calendar,
            'events' => (int) $row->events,
            'all_day' => (int) $row->all_day,
            'repeating' => (int) $row->repeating,
        ])->all());
    }
}

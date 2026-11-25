<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Calendar\Enums\CalendarView;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One member's calendar settings: their own time zone (null: the workspace's), week start, clock, default view and
 * durations, and which calendars they hide or which teammates they follow.
 *
 * @property string $id
 * @property string $membership_id
 * @property string|null $timezone
 * @property int $week_starts_on
 * @property string $time_format
 * @property CalendarView $default_view
 * @property int $default_duration
 * @property int|null $default_reminder
 * @property bool $show_declined
 * @property list<string>|null $hidden_calendar_ids
 * @property list<string>|null $followed_member_ids
 */
class Preference extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'calendar_preferences';

    protected $fillable = [
        'membership_id', 'timezone', 'week_starts_on', 'time_format', 'default_view', 'default_duration',
        'default_reminder', 'show_declined', 'hidden_calendar_ids', 'followed_member_ids',
    ];

    protected $attributes = [
        'week_starts_on' => 0,
        'time_format' => '12h',
        'default_view' => 'week',
        'default_duration' => 60,
        'default_reminder' => 10,
        'show_declined' => true,
    ];

    protected function casts(): array
    {
        return [
            'week_starts_on' => 'integer',
            'default_view' => CalendarView::class,
            'default_duration' => 'integer',
            'default_reminder' => 'integer',
            'show_declined' => 'boolean',
            'hidden_calendar_ids' => 'array',
            'followed_member_ids' => 'array',
        ];
    }
}

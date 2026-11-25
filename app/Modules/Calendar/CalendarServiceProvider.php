<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Calendar\Automation\Triggers\CalendarEventCreatedTrigger;
use App\Modules\Calendar\Jobs\SendCalendarReminders;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Reporting\ReportProviders;

/** First-party team calendar: personal and workspace calendars, repeating events, guests and reminders. */
final class CalendarServiceProvider extends ModuleServiceProvider
{
    public const string MODULE = 'calendar';

    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: self::MODULE,
            name: 'Calendar',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('calendar.events.view', 'calendar'),
                new PermissionDefinition('calendar.events.manage', 'calendar'),
                new PermissionDefinition('calendar.shared.manage', 'calendar'),
            ],
            navigation: [
                new NavigationItem('calendar', 'calendar.index', 'calendar-days', 'calendar.events.view', 'apps', 10, 'calendar.*'),
            ],
            triggers: [
                CalendarEventCreatedTrigger::class,
            ],
            morphMap: [
                'calendar_calendar' => Calendar::class,
                'calendar_event' => CalendarEvent::class,
            ],
            description: 'Plan your day and your team\'s: personal and shared calendars, repeating events, guests and reminders, in every time zone.',
            category: AppCategory::Productivity,
            icon: 'calendar-days',
            features: [
                'Day, week, month, year and schedule views with drag and drop',
                'Repeating events, guests with replies, and reminders',
                'Personal and workspace calendars, your own time zone',
            ],
            offers: [
                new AppOffer('free', 'Free', AppBillingType::Free),
            ],
            releaseNotes: 'Initial Calendar release.',
            routes: [
                'calendar.index',
                'calendar.preferences.update',
                'calendar.calendars.toggle',
                'calendar.people.store',
                'calendar.people.destroy',
                'calendar.events.respond',
                'calendar.events.store',
                'calendar.events.update',
                'calendar.events.move',
                'calendar.events.destroy',
                'calendar.calendars.store',
                'calendar.calendars.update',
                'calendar.calendars.destroy',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('calendar.reminders', SendCalendarReminders::class, ScheduledJobFrequency::EveryMinute),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}

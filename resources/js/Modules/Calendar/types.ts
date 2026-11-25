import type { CalendarView } from './lib/dates';

export type EventColor = 'red' | 'pink' | 'orange' | 'yellow' | 'sage' | 'green' | 'teal' | 'blue' | 'lavender' | 'purple' | 'graphite';

export type GuestResponse = 'needs_action' | 'accepted' | 'tentative' | 'declined';

export type Frequency = 'daily' | 'weekly' | 'monthly' | 'yearly';

export type MonthlyMode = 'day' | 'weekday' | 'last_weekday';

export type EditScope = 'this' | 'following' | 'all';

export type Recurrence = {
    frequency: Frequency;
    interval: number;
    weekdays: number[];
    monthly: MonthlyMode;
    count: number | null;
    until: string | null;
};

export interface Person {
    id: string;
    name: string;
}

export interface Guest extends Person {
    response: GuestResponse;
}

/** One occurrence on the page, in the viewer's zone. A busy-only one carries its times and nothing else. */
export interface CalendarEvent {
    key: string;
    id: string;
    seriesId: string | null;
    occurrence: string | null;
    calendarId: string;
    color: EventColor;
    allDay: boolean;
    /** `YYYY-MM-DDTHH:mm`, or `YYYY-MM-DD` for an all-day event. */
    start: string;
    /** Exclusive end, same form as `start`. */
    end: string;
    busy: boolean;
    title: string | null;
    editable: boolean;
    recurring: boolean;
    description?: string | null;
    location?: string | null;
    timezone?: string;
    eventColor?: EventColor | null;
    showAs?: 'busy' | 'free';
    visibility?: 'default' | 'private';
    reminders?: number[];
    recurrence?: Recurrence | null;
    organizer?: Person | null;
    guests?: Guest[];
    myResponse?: GuestResponse | null;
}

export interface CalendarInfo {
    id: string;
    name: string;
    description: string | null;
    color: EventColor;
    visibility: 'private' | 'busy' | 'team';
    isPrimary: boolean;
    shared: boolean;
    mine: boolean;
    owner: Person | null;
    busyOnly: boolean;
    visible: boolean;
    editable: boolean;
}

export interface CalendarPreferences {
    timezone: string | null;
    weekStartsOn: number;
    timeFormat: '12h' | '24h';
    defaultView: CalendarView;
    defaultDuration: number;
    defaultReminder: number | null;
    showDeclined: boolean;
}

export interface SearchResult {
    id: string;
    title: string;
    location: string | null;
    allDay: boolean;
    start: string;
    end: string;
    color: EventColor;
    recurring: boolean;
}

/** What a new event starts from when someone clicks or drags on the grid. */
export interface EventDraft {
    start: string;
    end: string;
    allDay: boolean;
}

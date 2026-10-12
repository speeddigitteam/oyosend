import type { Cell, ReportColumn, ReportFilter, Row, Visibility } from './types';

/** Pure helpers for the reports pages. Nothing here touches Vue, i18n or the DOM. */

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Null and undefined are "not reported", never zero. An empty string is not a value either. */
export function isReported(value: Cell): boolean {
    return value !== null && value !== undefined && value !== '';
}

function dayNumber(value: string): number | null {
    if (!DATE.test(value)) {
        return null;
    }

    const time = Date.parse(`${value}T00:00:00Z`);

    return Number.isNaN(time) ? null : Math.round(time / 86_400_000);
}

/** Calendar days from `from` to `to`, both included. Null when either is not a valid `Y-m-d`. */
export function daysInclusive(from: string, to: string): number | null {
    const start = dayNumber(from);
    const end = dayNumber(to);

    return start === null || end === null ? null : end - start + 1;
}

export type RangeProblem = 'missing' | 'order' | 'tooLong';

/** Mirrors the server's bound: `to >= from` and at most `maxDays` days inclusive. */
export function rangeProblem(from: string, to: string, maxDays: number): RangeProblem | null {
    const days = daysInclusive(from, to);

    if (days === null) {
        return 'missing';
    }

    if (days < 1) {
        return 'order';
    }

    return days > maxDays ? 'tooLong' : null;
}

/** A sensible "last N days" for a saved report from the range in the form. */
export function rangeDays(from: string, to: string, maxDays: number, fallback: number): number {
    const days = daysInclusive(from, to);

    if (days === null || days < 1) {
        return fallback;
    }

    return Math.min(days, maxDays);
}

/** Basis points (10000 = 100%) as a ratio for `Intl` percent formatting. */
export function basisPointsToRatio(basisPoints: number): number {
    return basisPoints / 10_000;
}

/** Hundredths (450 = 4.50) as a number. */
export function hundredthsToNumber(hundredths: number): number {
    return hundredths / 100;
}

/** Width, 0 to 100, of the small bar drawn beside a basis-points figure. */
export function barWidth(basisPoints: number): number {
    return Math.max(0, Math.min(100, Math.round(basisPoints / 100)));
}

export interface DurationParts {
    negative: boolean;
    hours: number;
    minutes: number;
}

/** Whole minutes split into hours and minutes. */
export function durationParts(totalMinutes: number): DurationParts {
    const negative = totalMinutes < 0;
    const absolute = Math.abs(Math.round(totalMinutes));

    return { negative, hours: Math.floor(absolute / 60), minutes: absolute % 60 };
}

/** Whether a currency code can be handed to `Intl`. */
export function isCurrencyCode(value: Cell): value is string {
    return typeof value === 'string' && /^[A-Za-z]{3}$/.test(value);
}

const HEADCOUNT_GROUPS: Record<string, string> = {
    employment_type: 'reports.enums.employmentType',
    status: 'reports.enums.employeeStatus',
    period: 'reports.enums.period',
};

/**
 * The i18n key that translates a row's enum-like text value, or null when the value is free text
 * (a name, a stage, a source) and must be shown as it is. Look it up with `te()` so an unknown
 * value falls back to the raw string.
 */
export function enumKey(reportKey: string, column: string, row: Row): string | null {
    const raw = row[column];

    if (typeof raw !== 'string' || raw === '') {
        return null;
    }

    switch (`${reportKey}:${column}`) {
        case 'hrm.headcount:dimension':
            return `reports.enums.dimension.${raw}`;
        case 'hrm.headcount:name': {
            const group = HEADCOUNT_GROUPS[String(row.dimension)];

            return group === undefined ? null : `${group}.${raw}`;
        }
        case 'projects.performance:status':
            return `reports.enums.projectStatus.${raw}`;
        case 'payroll.run_totals:kind':
            return `reports.enums.payrollKind.${raw}`;
        case 'support.sla:target':
            return `reports.enums.slaTarget.${raw}`;
        case 'talent.recruitment_funnel:stage_type':
            return `reports.enums.stageType.${raw}`;
        default:
            return null;
    }
}

/** A filter value as the form holds it: always a string; booleans are '', '1' or '0'. */
export function filterToDraft(value: string | number | boolean | undefined): string {
    if (value === undefined || value === null) {
        return '';
    }

    if (typeof value === 'boolean') {
        return value ? '1' : '0';
    }

    return String(value);
}

/** The filters worth sending: declared ones with a value, trimmed. */
export function cleanFilters(filters: ReportFilter[], draft: Record<string, string | number | null | undefined>): Record<string, string> {
    const clean: Record<string, string> = {};

    for (const filter of filters) {
        const value = String(draft[filter.key] ?? '').trim();

        if (value !== '') {
            clean[filter.key] = value;
        }
    }

    return clean;
}

/**
 * Currency columns that ride along with the chosen money columns: the server always adds them, so
 * the picker shows them ticked and fixed.
 */
export function forcedColumns(columns: ReportColumn[], selected: string[]): string[] {
    const forced = new Set<string>();

    for (const column of columns) {
        if (column.type === 'money' && column.currencyColumn !== null && selected.includes(column.key)) {
            forced.add(column.currencyColumn);
        }
    }

    return [...forced];
}

/** Selected columns in the report's declared order. */
export function orderedSelection(columns: ReportColumn[], selected: string[]): string[] {
    return columns.map((column) => column.key).filter((key) => selected.includes(key));
}

/** The query for a run: no dates for a snapshot report, no empty filters. */
export function buildQuery(input: {
    usesDateRange: boolean;
    from: string;
    to: string;
    columns: string[];
    filters: Record<string, string>;
}): Record<string, unknown> {
    const query: Record<string, unknown> = {};

    if (input.usesDateRange) {
        query.from = input.from;
        query.to = input.to;
    }

    if (input.columns.length > 0) {
        query.columns = input.columns;
    }

    if (Object.keys(input.filters).length > 0) {
        query.filters = input.filters;
    }

    return query;
}

/** Whether a share target is chosen when the visibility needs one. */
export function sharingComplete(visibility: Visibility, roleId: string, departmentId: string): boolean {
    if (visibility === 'role') {
        return roleId !== '';
    }

    return visibility === 'department' ? departmentId !== '' : true;
}

export function isNumericType(type: ReportColumn['type']): boolean {
    return type === 'integer' || type === 'money' || type === 'basis_points' || type === 'hundredths' || type === 'minutes';
}

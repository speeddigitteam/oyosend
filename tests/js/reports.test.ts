import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: [] as string[] },
            errors: {} as Record<string, string>,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    useForm: (initial: Record<string, unknown>) => reactive({
        ...structuredClone(initial),
        errors: {},
        processing: false,
        clearErrors: () => {},
        transform: () => {},
        post: () => {},
        put: () => {},
    }),
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, unknown> = {}) => `/${name}?${new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)])).toString()}`,
}));

import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import {
    barWidth,
    basisPointsToRatio,
    buildQuery,
    cleanFilters,
    daysInclusive,
    durationParts,
    enumKey,
    filterToDraft,
    forcedColumns,
    hundredthsToNumber,
    isReported,
    orderedSelection,
    rangeDays,
    rangeProblem,
    sharingComplete,
} from '@/Modules/Reports/Components/reportFormat';
import type { ReportColumn, ReportDefinition, ReportResult, RunValues } from '@/Modules/Reports/Components/types';
import Run from '@/Modules/Reports/Pages/Run.vue';

type Tree = { [key: string]: Tree | string };

function flatten(tree: Tree, prefix = ''): Map<string, string> {
    const flat = new Map<string, string>();

    for (const [key, value] of Object.entries(tree)) {
        const path = prefix === '' ? key : `${prefix}|${key}`;

        if (typeof value === 'string') {
            flat.set(path, value);
        } else {
            for (const [childPath, childValue] of flatten(value, path)) {
                flat.set(childPath, childValue);
            }
        }
    }

    return flat;
}

function placeholders(message: string): string[] {
    return [...message.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();
}

const english = flatten(en.reports as unknown as Tree);
const bengali = flatten(bn.reports as unknown as Tree);

describe('reports locale', () => {
    it('has the same keys in Bengali as in English', () => {
        expect([...bengali.keys()].sort()).toEqual([...english.keys()].sort());
    });

    it('keeps placeholders and has no empty or unsafe messages', () => {
        const mismatched = [...english].filter(([key, value]) => placeholders(value).join() !== placeholders(bengali.get(key) ?? '').join()).map(([key]) => key);
        expect(mismatched).toEqual([]);

        for (const messages of [english, bengali]) {
            expect([...messages].filter(([, value]) => value.trim() === '' || /[@|]/.test(value)).map(([key]) => key)).toEqual([]);
        }
    });

    it('translates every key used by the pages', () => {
        const used = new Set<string>();
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    const source = readFileSync(path, 'utf8').replace(/route\(\s*['`][^'`]*['`]/g, '');

                    for (const match of source.matchAll(/['`]reports\.([A-Za-z0-9_.]+)['`]/g)) {
                        used.add(match[1]!.replaceAll('.', '|'));
                    }
                }
            }
        };
        walk(join(process.cwd(), 'resources/js/Modules/Reports'));

        const missing = [...used].filter((key) => ![...english.keys()].some((known) => known === key || known.startsWith(`${key}|`)));

        expect(missing).toEqual([]);
    });

    it('translates every enum value a provider sends', () => {
        const groups: Record<string, string[]> = {
            employmentType: ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
            employeeStatus: ['probation', 'active', 'on_leave', 'suspended', 'terminated'],
            projectStatus: ['planned', 'active', 'on_hold', 'completed', 'cancelled'],
            payrollKind: ['regular', 'adjustment', 'reversal'],
            slaTarget: ['first_response', 'resolution'],
            stageType: ['active', 'hired', 'rejected'],
            period: ['joined', 'left'],
            dimension: ['total', 'department', 'branch', 'designation', 'employment_type', 'status', 'period'],
        };
        const missing = Object.entries(groups).flatMap(([group, values]) => values.filter((value) => !english.has(`enums|${group}|${value}`)).map((value) => `${group}.${value}`));

        expect(missing).toEqual([]);
    });
});

describe('reports catalogue', () => {
    const catalogue = en.catalogue as unknown as {
        modules: Record<string, string>;
        permissionGroups: Record<string, string>;
        permissions: Record<string, { label: string; description: string }>;
        limits: Record<string, unknown>;
        events: Record<string, string>;
    };
    const bnCatalogue = bn.catalogue as unknown as typeof catalogue;

    it('names the module, group, permissions, limit and audit events in both languages', () => {
        for (const source of [catalogue, bnCatalogue]) {
            expect(source.modules.reports).toBeTruthy();
            expect(source.permissionGroups.reports).toBeTruthy();
            expect(['reports.reports.view', 'reports.reports.export', 'reports.saved.manage', 'reports.saved.manage_all'].filter((key) => !source.permissions[key]?.label || !source.permissions[key]?.description)).toEqual([]);
            expect(source.limits['reports.saved_reports']).toBeTruthy();
            expect(['reports.saved.created', 'reports.saved.updated', 'reports.saved.deleted', 'reports.report.exported'].filter((key) => !source.events[key])).toEqual([]);
        }
    });

    it('has the navigation label the manifest names and the app description', () => {
        expect((en.shell as unknown as { nav: Record<string, string> }).nav.reports).toBeTruthy();
        expect((bn.shell as unknown as { nav: Record<string, string> }).nav.reports).toBeTruthy();

        for (const source of [en.appStore, bn.appStore] as unknown as { catalog: Record<string, { name: string }> }[]) {
            expect(source.catalog.reports?.name).toBeTruthy();
        }
    });
});

describe('report formatting helpers', () => {
    it('treats null and empty as not reported but keeps zero', () => {
        expect(isReported(null)).toBe(false);
        expect(isReported(undefined)).toBe(false);
        expect(isReported('')).toBe(false);
        expect(isReported(0)).toBe(true);
        expect(isReported('0')).toBe(true);
        expect(isReported(false)).toBe(true);
    });

    it('converts basis points, hundredths and minutes', () => {
        expect(basisPointsToRatio(2550)).toBeCloseTo(0.255);
        expect(basisPointsToRatio(10000)).toBe(1);
        expect(hundredthsToNumber(450)).toBe(4.5);
        expect(durationParts(135)).toEqual({ negative: false, hours: 2, minutes: 15 });
        expect(durationParts(45)).toEqual({ negative: false, hours: 0, minutes: 45 });
        expect(durationParts(-90)).toEqual({ negative: true, hours: 1, minutes: 30 });
        expect(barWidth(12500)).toBe(100);
        expect(barWidth(-5)).toBe(0);
        expect(barWidth(5000)).toBe(50);
    });

    it('counts days inclusively and enforces the 366-day bound', () => {
        expect(daysInclusive('2026-01-01', '2026-01-01')).toBe(1);
        expect(daysInclusive('2026-01-01', '2026-12-31')).toBe(365);
        expect(daysInclusive('2026-01-01', 'nope')).toBeNull();
        expect(rangeProblem('2026-01-01', '2026-01-31', 366)).toBeNull();
        expect(rangeProblem('2026-01-02', '2026-01-01', 366)).toBe('order');
        expect(rangeProblem('', '2026-01-01', 366)).toBe('missing');
        expect(rangeProblem('2025-01-01', '2026-01-02', 366)).toBe('tooLong');
        expect(rangeProblem('2025-01-01', '2026-01-01', 366)).toBeNull();
        expect(rangeProblem('2024-01-01', '2024-12-31', 366)).toBeNull();
        expect(rangeDays('2026-01-01', '2026-01-10', 366, 30)).toBe(10);
        expect(rangeDays('2020-01-01', '2026-01-10', 366, 30)).toBe(366);
        expect(rangeDays('', '', 366, 30)).toBe(30);
    });

    it('maps enum-like text to catalogue keys and leaves free text alone', () => {
        expect(enumKey('hrm.headcount', 'dimension', { dimension: 'employment_type' })).toBe('reports.enums.dimension.employment_type');
        expect(enumKey('hrm.headcount', 'name', { dimension: 'employment_type', name: 'full_time' })).toBe('reports.enums.employmentType.full_time');
        expect(enumKey('hrm.headcount', 'name', { dimension: 'status', name: 'on_leave' })).toBe('reports.enums.employeeStatus.on_leave');
        expect(enumKey('hrm.headcount', 'name', { dimension: 'department', name: 'Sales' })).toBeNull();
        expect(enumKey('hrm.headcount', 'name', { dimension: 'total', name: null })).toBeNull();
        expect(enumKey('projects.performance', 'status', { status: 'on_hold' })).toBe('reports.enums.projectStatus.on_hold');
        expect(enumKey('payroll.run_totals', 'kind', { kind: 'reversal' })).toBe('reports.enums.payrollKind.reversal');
        expect(enumKey('support.sla', 'target', { target: 'first_response' })).toBe('reports.enums.slaTarget.first_response');
        expect(enumKey('crm.pipeline', 'stage', { stage: 'Negotiation' })).toBeNull();
    });

    it('keeps filters as strings and drops empty ones', () => {
        const filters = [
            { key: 'status', label: 'Status', type: 'select' as const, options: [], min: null, max: null },
            { key: 'currency', label: 'Currency', type: 'currency' as const, options: [], min: null, max: null },
            { key: 'year', label: 'Year', type: 'integer' as const, options: [], min: 2000, max: 2100 },
        ];

        expect(cleanFilters(filters, { status: '', currency: ' BDT ', year: 2026 })).toEqual({ currency: 'BDT', year: '2026' });
        expect(filterToDraft(true)).toBe('1');
        expect(filterToDraft(false)).toBe('0');
        expect(filterToDraft(2026)).toBe('2026');
        expect(filterToDraft(undefined)).toBe('');
    });

    it('adds the currency column of a chosen money column and keeps declared order', () => {
        const columns: ReportColumn[] = [
            { key: 'currency', label: 'Currency', type: 'text', currencyColumn: null },
            { key: 'deals', label: 'Deals', type: 'integer', currencyColumn: null },
            { key: 'amount', label: 'Amount', type: 'money', currencyColumn: 'currency' },
        ];

        expect(forcedColumns(columns, ['amount'])).toEqual(['currency']);
        expect(forcedColumns(columns, ['deals'])).toEqual([]);
        expect(orderedSelection(columns, ['amount', 'deals'])).toEqual(['deals', 'amount']);
    });

    it('builds a query without dates for a snapshot report', () => {
        const input = { from: '2026-10-01', to: '2026-10-31', columns: ['a'], filters: { currency: 'BDT' } };

        expect(buildQuery({ usesDateRange: true, ...input })).toEqual({ from: '2026-10-01', to: '2026-10-31', columns: ['a'], filters: { currency: 'BDT' } });
        expect(buildQuery({ usesDateRange: false, ...input, columns: [], filters: {} })).toEqual({});
    });

    it('requires a role or department when sharing with one', () => {
        expect(sharingComplete('private', '', '')).toBe(true);
        expect(sharingComplete('role', '', '')).toBe(false);
        expect(sharingComplete('role', 'r1', '')).toBe(true);
        expect(sharingComplete('department', 'r1', '')).toBe(false);
        expect(sharingComplete('department', '', 'd1')).toBe(true);
    });
});

describe('run page', () => {
    const report: ReportDefinition = {
        key: 'crm.pipeline',
        module: 'crm',
        label: 'Pipeline',
        description: 'Open deals by stage.',
        usesDateRange: true,
        columns: [
            { key: 'stage', label: 'Stage', type: 'text', currencyColumn: null },
            { key: 'currency', label: 'Currency', type: 'text', currencyColumn: null },
            { key: 'deals', label: 'Deals', type: 'integer', currencyColumn: null },
            { key: 'amount', label: 'Amount', type: 'money', currencyColumn: 'currency' },
            { key: 'rate', label: 'Rate', type: 'basis_points', currencyColumn: null },
        ],
        defaultColumns: ['stage', 'currency', 'deals', 'amount', 'rate'],
        filters: [{ key: 'status', label: 'Status', type: 'select', options: [{ value: 'open', label: 'Open' }], min: null, max: null }],
    };
    const values: RunValues = { from: '2026-10-01', to: '2026-10-31', columns: report.defaultColumns, filters: {} };
    const result: ReportResult = {
        columns: report.columns,
        rows: [
            { stage: 'Qualified', currency: 'BDT', deals: 3, amount: 1250000, rate: 2550 },
            { stage: 'Proposal', currency: 'BDT', deals: 0, amount: null, rate: null },
        ],
        page: { currentPage: 1, lastPage: 1, perPage: 50, total: 2 },
        truncated: false,
        rowLimit: 2000,
        scope: 'all',
        from: '2026-10-01',
        to: '2026-10-31',
    };
    const base = {
        report,
        values,
        result,
        saved: null,
        bounds: { maxDays: 366, defaultDays: 30, rowLimit: 2000 },
        canExport: false,
        canSave: false,
        share: { roles: [], departments: [] },
        savedLimit: { used: 0, max: null },
    };

    it('formats money, percentages and counts, and shows a dash rather than zero for null', () => {
        const wrapper = mount(Run, { props: base });
        const rows = wrapper.findAll('table tbody tr');

        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain('12,500.00');
        expect(rows[0]!.text()).toContain('25.5%');
        expect(rows[1]!.text()).toContain('Not reported');
        expect(rows[1]!.findAll('td')[3]!.text()).not.toMatch(/0\.00/);
        expect(rows[1]!.findAll('td')[3]!.text()).toBe('–Not reported');
        // Zero is a real figure.
        expect(rows[1]!.findAll('td')[2]!.text()).toBe('0');
    });

    it('shows the scope and truncation notices and gates export and save on the flags', () => {
        const plain = mount(Run, { props: base });

        expect(plain.find('[data-testid="scope-notice"]').exists()).toBe(false);
        expect(plain.find('[data-testid="truncated"]').exists()).toBe(false);
        expect(plain.find('[data-testid="export"]').exists()).toBe(false);

        const full = mount(Run, {
            props: { ...base, canExport: true, canSave: true, result: { ...result, scope: 'department', truncated: true } },
        });

        expect(full.get('[data-testid="scope-notice"]').text()).toContain('Figures cover only the records you may see.');
        expect(full.get('[data-testid="truncated"]').text()).toContain('2,000');
        expect(full.get('[data-testid="export"]').attributes('href')).toContain('/reports.export?');
    });

    it('blocks a run over 366 days', async () => {
        const wrapper = mount(Run, { props: { ...base, values: { ...values, from: '2025-01-01', to: '2026-10-31' } } });

        expect(wrapper.get('[data-testid="range-problem"]').text()).toContain('366');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});

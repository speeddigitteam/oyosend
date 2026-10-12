import { mount } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    usePage: () => ({
        props: { app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' }, auth: { permissions: [] } },
        flash: {},
    }),
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => `/${name}?${new URLSearchParams(params).toString()}`,
}));

import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import MessageThread from '@/Modules/Support/Components/MessageThread.vue';
import {
    basisPointsToPercent,
    clockMinutes,
    clockState,
    durationParts,
    fileSize,
    hundredthsToRating,
    isFinished,
    minutesUntil,
    parseTags,
    primaryClock,
    priorityTone,
    statusTone,
    ticketClocks,
} from '@/Modules/Support/Components/supportFormat';
import type { MessageRow, TicketSummary } from '@/Modules/Support/Components/types';

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

const english = flatten(en.support as unknown as Tree);
const bengali = flatten(bn.support as unknown as Tree);

describe('support locale', () => {
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

    it('labels every enum the server sends', () => {
        const groups: Record<string, string[]> = {
            status: ['new', 'open', 'waiting_customer', 'on_hold', 'resolved', 'closed', 'merged'],
            priority: ['low', 'normal', 'high', 'urgent'],
            channel: ['web', 'email', 'phone', 'chat', 'api', 'internal'],
            messageKind: ['public_reply', 'customer_reply', 'internal_note'],
            surveyStatus: ['pending', 'answered', 'expired'],
            articleStatus: ['draft', 'published', 'archived'],
            assignmentMode: ['manual', 'round_robin'],
            event: [
                'created', 'updated', 'assigned', 'unassigned', 'status_changed', 'priority_changed', 'queue_changed', 'replied', 'noted',
                'customer_replied', 'merged_into', 'merged_from', 'split_from', 'split_to', 'sla_paused', 'sla_resumed', 'sla_breached',
                'survey_requested', 'survey_answered',
            ],
        };
        const missing = Object.entries(groups).flatMap(([group, values]) => values.filter((value) => !english.has(`${group}|${value}`)).map((value) => `${group}.${value}`));

        expect(missing).toEqual([]);
    });

    it('translates every key used by the pages', () => {
        const used = new Set<string>();
        const walk = (directory: string): void => {
            for (const name of readdirSync(directory)) {
                const path = join(directory, name);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (/\.(vue|ts)$/.test(name)) {
                    const source = readFileSync(path, 'utf8');

                    // Route names, permission names and the like are not messages.
                    const stripped = source
                        .replace(/route\(\s*['`][^'`]*['`]/g, '')
                        .replace(/\b(name|permission):\s*['`][^'`]*['`]/g, '')
                        .replace(/\bpost\(\s*['`][^'`]*['`]/g, '');

                    for (const match of stripped.matchAll(/['`]support\.([A-Za-z0-9_.]+)['`]/g)) {
                        used.add(match[1]!.replaceAll('.', '|'));
                    }
                }
            }
        };
        walk(join(process.cwd(), 'resources/js/Modules/Support'));

        const missing = [...used].filter((key) => ![...english.keys()].some((known) => known === key || known.startsWith(`${key}|`)));

        expect(missing).toEqual([]);
    });
});

describe('support catalogue', () => {
    const catalogue = en.catalogue as unknown as { modules: Record<string, string>; permissionGroups: Record<string, string>; permissions: Record<string, unknown>; limits: Record<string, unknown>; automation: { triggers: Record<string, string>; actions: Record<string, string> } };
    const bnCatalogue = bn.catalogue as unknown as typeof catalogue;

    it('names the module, permission groups, permissions and limits in both languages', () => {
        for (const source of [catalogue, bnCatalogue]) {
            expect(source.modules.support).toBeTruthy();
            expect(['support_tickets', 'support_knowledge', 'support_configuration', 'support_reports'].filter((key) => !source.permissionGroups[key])).toEqual([]);
            expect(Object.keys(source.permissions).filter((key) => key.startsWith('support.'))).toHaveLength(16);
            expect(['support.tickets_per_month', 'support.agents'].filter((key) => !source.limits[key])).toEqual([]);
            expect(Object.keys(source.automation.triggers).filter((key) => key.startsWith('support.'))).toHaveLength(6);
            expect(Object.keys(source.automation.actions).filter((key) => key.startsWith('support.'))).toHaveLength(3);
        }
    });

    it('labels the desk audit family apart from support access', () => {
        for (const source of [en.catalogue, bn.catalogue] as unknown as { eventFamilies: Record<string, string> }[]) {
            expect(source.eventFamilies.support_desk).toBeTruthy();
            expect(source.eventFamilies.support).toBeTruthy();
            expect(source.eventFamilies.support_desk).not.toBe(source.eventFamilies.support);
        }
    });

    it('has the navigation labels the manifest names', () => {
        const nav = (en.shell as unknown as { nav: Record<string, string> }).nav;
        const bnNav = (bn.shell as unknown as { nav: Record<string, string> }).nav;

        for (const key of ['supportTickets', 'supportKb', 'supportCanned', 'supportReports', 'supportQueues', 'supportSla', 'supportSettings']) {
            expect(nav[key], key).toBeTruthy();
            expect(bnNav[key], key).toBeTruthy();
        }
    });
});

describe('support figures', () => {
    it('converts basis points to a percentage and hundredths to a rating', () => {
        expect(basisPointsToPercent(8750)).toBe(87.5);
        expect(basisPointsToPercent(null)).toBeNull();
        expect(hundredthsToRating(450)).toBe(4.5);
        expect(hundredthsToRating(null)).toBeNull();
    });

    it('splits whole minutes into days, hours and minutes', () => {
        expect(durationParts(0)).toEqual({ days: 0, hours: 0, minutes: 0 });
        expect(durationParts(135)).toEqual({ days: 0, hours: 2, minutes: 15 });
        expect(durationParts(1440 + 61)).toEqual({ days: 1, hours: 1, minutes: 1 });
        expect(durationParts(-90)).toEqual({ days: 0, hours: 1, minutes: 30 });
    });

    it('formats file sizes and parses tags', () => {
        expect(fileSize(512)).toEqual({ value: 512, unit: 'b' });
        expect(fileSize(2048)).toEqual({ value: 2, unit: 'kb' });
        expect(fileSize(5 * 1024 * 1024)).toEqual({ value: 5, unit: 'mb' });
        expect(parseTags(' vip, Billing ,vip,, BILLING ')).toEqual(['vip', 'Billing']);
    });

    it('parses business-hour times including 24:00', () => {
        expect(clockMinutes('09:30')).toBe(570);
        expect(clockMinutes('24:00')).toBe(1440);
        expect(clockMinutes('24:30')).toBeNull();
        expect(clockMinutes('9am')).toBeNull();
    });

    it('maps statuses and priorities to tones and knows finished statuses', () => {
        expect(statusTone('resolved')).toBe('ok');
        expect(statusTone('waiting_customer')).toBe('warn');
        expect(priorityTone('urgent')).toBe('danger');
        expect(isFinished('closed')).toBe(true);
        expect(isFinished('open')).toBe(false);
    });
});

describe('sla clocks', () => {
    const now = Date.parse('2026-10-11T10:00:00Z');
    const base: TicketSummary = {
        id: 't1',
        number: 'TKT-00001',
        subject: 'Cannot sign in',
        status: 'open',
        priority: 'normal',
        channel: 'web',
        requester: { name: 'Rahim', email: null, contactId: null, companyId: null },
        queue: null,
        category: null,
        assignee: null,
        tags: [],
        firstRespondedAt: null,
        firstResponseDueAt: '2026-10-11T11:00:00Z',
        resolutionDueAt: '2026-10-12T10:00:00Z',
        slaPaused: false,
        firstResponseBreached: false,
        resolutionBreached: false,
        lastActivityAt: null,
        createdAt: '2026-10-11T09:00:00Z',
    };

    it('judges one clock', () => {
        expect(clockState({ done: false, dueAt: '2026-10-11T11:00:00Z', breached: false, paused: false, now })).toBe('due');
        expect(clockState({ done: false, dueAt: '2026-10-11T09:00:00Z', breached: false, paused: false, now })).toBe('overdue');
        expect(clockState({ done: false, dueAt: '2026-10-11T09:00:00Z', breached: true, paused: false, now })).toBe('breached');
        expect(clockState({ done: false, dueAt: null, breached: false, paused: true, now })).toBe('paused');
        expect(clockState({ done: false, dueAt: null, breached: false, paused: false, now })).toBe('none');
        expect(clockState({ done: true, dueAt: '2026-10-11T09:00:00Z', breached: true, paused: false, now })).toBe('missed');
        expect(clockState({ done: true, dueAt: null, breached: false, paused: false, now })).toBe('met');
        expect(minutesUntil('2026-10-11T11:30:00Z', now)).toBe(90);
        expect(minutesUntil(null, now)).toBeNull();
    });

    it('shows first response until it is given, then resolution', () => {
        expect(primaryClock(base, now).target).toBe('first_response');
        expect(primaryClock({ ...base, firstRespondedAt: '2026-10-11T09:30:00Z' }, now).target).toBe('resolution');
        expect(ticketClocks({ ...base, status: 'resolved', firstRespondedAt: '2026-10-11T09:30:00Z' }, now).resolution.state).toBe('met');
        expect(ticketClocks({ ...base, slaPaused: true, status: 'waiting_customer', firstRespondedAt: 'x' }, now).resolution.state).toBe('paused');
    });
});

describe('message thread', () => {
    const messages: MessageRow[] = [
        { id: 'm1', kind: 'customer_reply', isInternal: false, isCustomer: true, author: { name: 'Rahim', email: null, membershipId: null }, body: 'Hello\nI cannot <b>sign in</b>', createdAt: '2026-10-11T09:00:00Z', attachments: [{ id: 'a1', name: 'screen.png', size: 2048, mimeType: 'image/png' }] },
        { id: 'm2', kind: 'internal_note', isInternal: true, author: { name: 'Agent', email: null, membershipId: 'x' }, isCustomer: false, body: 'Checked the logs', createdAt: '2026-10-11T09:05:00Z', attachments: [] },
        { id: 'm3', kind: 'public_reply', isInternal: false, isCustomer: false, author: { name: 'Agent', email: null, membershipId: 'x' }, body: 'Try again now', createdAt: '2026-10-11T09:10:00Z', attachments: [] },
    ];

    it('labels notes and replies in words and renders bodies as text', () => {
        const wrapper = mount(MessageThread, { props: { ticketId: 't1', messages } });
        const text = wrapper.text();

        expect(text).toContain('Internal note');
        expect(text).toContain('Only your team can see this note.');
        expect(text).toContain('Reply to customer');
        expect(text).toContain('From customer');
        // User content is text, never markup.
        expect(wrapper.find('b').exists()).toBe(false);
        expect(text).toContain('<b>sign in</b>');
        expect(wrapper.find('p.whitespace-pre-wrap').exists()).toBe(true);
    });

    it('links attachments to the download route', () => {
        const wrapper = mount(MessageThread, { props: { ticketId: 't1', messages } });
        const link = wrapper.get('a[download]');

        expect(link.attributes('href')).toContain('/support.tickets.attachments.download?ticket=t1&attachment=a1');
    });

    it('offers a checkbox per message while splitting', () => {
        const wrapper = mount(MessageThread, { props: { ticketId: 't1', messages, selectable: true, selected: [] } });

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(3);
    });
});

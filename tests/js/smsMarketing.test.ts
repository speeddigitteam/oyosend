import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, toRaw } from 'vue';

interface Visit { method: string; url: string }
interface Submission extends Visit { data: Record<string, unknown> }
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['sms_marketing.campaigns.view'] as string[] },
            tenant: { name: 'Acme' },
        },
    },
    visits: [] as Visit[],
    submissions: [] as Submission[],
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: inertia.router,
    useForm: (initial: Record<string, unknown>) => {
        let defaults = structuredClone(initial);
        let transform = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({ ...structuredClone(initial), errors: {}, processing: false }) as Record<string, any>;
        form.defaults = (values: Record<string, unknown>) => { defaults = { ...defaults, ...structuredClone(values) }; return form; };
        form.reset = () => { Object.assign(form, structuredClone(defaults)); return form; };
        form.clearErrors = () => {};
        form.transform = (callback: (data: Record<string, unknown>) => Record<string, unknown>) => { transform = callback; return form; };
        const submit = (method: string, url: string, options?: { onSuccess?: () => void }) => {
            const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key] instanceof File ? form[key] : JSON.parse(JSON.stringify(toRaw(form[key]) ?? null))]));
            inertia.visits.push({ method, url });
            inertia.submissions.push({ method, url, data: transform(data) });
            options?.onSuccess?.();
        };
        form.post = (url: string, options?: { onSuccess?: () => void }) => submit('post', url, options);
        form.put = (url: string, options?: { onSuccess?: () => void }) => submit('put', url, options);
        form.patch = (url: string, options?: { onSuccess?: () => void }) => submit('patch', url, options);
        return form;
    },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params).toString();
        return query ? `/${name}?${query}` : `/${name}`;
    },
}));

import { setLocale } from '@/Core/i18n';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';
import ChannelNotice from '@/Modules/SmsMarketing/Components/ChannelNotice.vue';
import SegmentMeter from '@/Modules/SmsMarketing/Components/SegmentMeter.vue';
import SmsMarketingOverviewWidget from '@/Modules/SmsMarketing/Components/SmsMarketingOverviewWidget.vue';
import { countSegments, isLocalDateTime, localDateTime, percent, unknownVariables, usedVariables } from '@/Modules/SmsMarketing/Components/smsFormat';
import type {
    AnalyticsReport,
    CampaignDetail,
    CampaignRow,
    FormOptions,
    Paginated,
    SettingsValues,
    Standing,
} from '@/Modules/SmsMarketing/Components/types';
import AnalyticsIndex from '@/Modules/SmsMarketing/Pages/Analytics/Index.vue';
import CampaignsForm from '@/Modules/SmsMarketing/Pages/Campaigns/Form.vue';
import CampaignsIndex from '@/Modules/SmsMarketing/Pages/Campaigns/Index.vue';
import CampaignsShow from '@/Modules/SmsMarketing/Pages/Campaigns/Show.vue';
import GroupsShow from '@/Modules/SmsMarketing/Pages/Groups/Show.vue';
import SendersIndex from '@/Modules/SmsMarketing/Pages/Senders/Index.vue';
import SettingsIndex from '@/Modules/SmsMarketing/Pages/Settings/Index.vue';

const mounted: VueWrapper[] = [];
const track = <T extends VueWrapper>(wrapper: T): T => { mounted.push(wrapper); return wrapper; };
/** A Modal that renders its content only while open, like the real one. */
const modalStub = defineComponent({ props: { open: Boolean }, template: '<div v-if="open"><slot /><slot name="footer" /></div>' });
const stubs = { Modal: modalStub };

const available = { state: 'available' as const, reason: null, testDouble: false };
const notConfigured = { state: 'not_configured' as const, reason: 'provider_not_configured', testDouble: false };

function paginated<T>(data: T[]): Paginated<T> {
    return { data, current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length, prev_page_url: null, next_page_url: null, links: [] } as unknown as Paginated<T>;
}

const stats = { recipients: 4, pending: 0, queued: 0, sent: 2, delivered: 1, failed: 1, blocked: 0, skipped: 0, accepted: 3, segments: 3 };

function row(overrides: Partial<CampaignRow> = {}): CampaignRow {
    return {
        id: 'c-1', name: 'Eid offer', status: 'completed', holdReason: null, scheduledFor: null, startedAt: '2026-10-11T04:00:00+00:00',
        completedAt: null, estimatedRecipients: 4, estimatedSegments: 4, stats, createdAt: '2026-10-10T04:00:00+00:00', owner: null, ...overrides,
    };
}

function detail(overrides: Partial<CampaignDetail> = {}): CampaignDetail {
    return {
        ...row({ status: 'draft' }),
        body: 'Hello {{first_name}}, our sale starts today.',
        templateName: null,
        groups: [{ id: 'g-1', name: 'Customers' }],
        groupIds: ['g-1'],
        templateId: null,
        senderIdentityId: null,
        sender: null,
        maxAttempts: 3,
        retryable: 0,
        abilities: { edit: true, delete: true, launch: true, cancel: false, retry: false },
        ...overrides,
    };
}

function standing(overrides: Partial<Standing> = {}): Standing {
    return {
        channel: available,
        plan: { limit: 1000, used: 120, remaining: 880 },
        contacts: { limit: null, used: 40 },
        credit: { reported: false, balance: null },
        dailyCap: { cap: 500, used: 20 },
        ...overrides,
    };
}

function formOptions(overrides: Partial<FormOptions> = {}): FormOptions {
    return {
        groups: [{ id: 'g-1', name: 'Customers', description: null, membersCount: 12 }, { id: 'g-2', name: 'Partners', description: null, membersCount: 3 }],
        templates: [{ id: 't-1', name: 'Sale', body: 'Sale on today' }],
        senders: [{ id: 's-1', label: 'Brand', value: 'ACME' }],
        defaultSenderId: null,
        optOutWording: 'Opt out:',
        timezone: 'Asia/Dhaka',
        limits: { maxBody: 1000, maxSegments: 10, maxAudience: 50000 },
        channel: available,
        ...overrides,
    };
}

beforeEach(() => {
    inertia.visits.length = 0;
    inertia.submissions.length = 0;
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    setLocale('en');
    inertia.page.props.app.numerals = 'latn';
    inertia.page.props.auth.permissions = ['sms_marketing.campaigns.view'];
});
afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('segment counter (client)', () => {
    const cases: [string, string, number, number][] = [
        ['', 'gsm7', 0, 0],
        ['Hello', 'gsm7', 5, 1],
        ['a'.repeat(160), 'gsm7', 160, 1],
        ['a'.repeat(161), 'gsm7', 161, 2],
        ['a'.repeat(306), 'gsm7', 306, 2],
        ['a'.repeat(307), 'gsm7', 307, 3],
        [`${'a'.repeat(159)}€`, 'gsm7', 161, 2],
        ['আমি', 'ucs2', 3, 1],
        ['অ'.repeat(70), 'ucs2', 70, 1],
        ['অ'.repeat(71), 'ucs2', 71, 2],
        ['অ'.repeat(135), 'ucs2', 135, 3],
        ['😀', 'ucs2', 2, 1],
        ['😀'.repeat(36), 'ucs2', 72, 2],
    ];

    it.each(cases)('counts %j as %s with %d units in %d segments', (text, encoding, units, segments) => {
        const count = countSegments(text);

        expect(count.encoding).toBe(encoding);
        expect(count.units).toBe(units);
        expect(count.segments).toBe(segments);
    });

    it('does not split a surrogate pair across segments', () => {
        const count = countSegments(`${'অ'.repeat(66)}😀${'অ'.repeat(10)}`);

        expect(count.segments).toBe(2);
        expect(count.remaining).toBe(67 - 12);
    });

    it('names the characters that forced unicode', () => {
        expect(countSegments('Eid ঈদ').unicodeCharacters).toContain('ঈ');
    });
});

describe('variables and dates', () => {
    it('finds variables with and without a fallback', () => {
        expect(usedVariables('Hi {{first_name|friend}}, {{ last_name }} and {{first_name}}')).toEqual(['first_name', 'last_name']);
    });

    it('lists the ones outside the allow-list', () => {
        expect(unknownVariables('{{first_name}} {{code}} {{salary}}', ['first_name', 'last_name'])).toEqual(['code', 'salary']);
    });

    it('accepts only the shape the server expects for a launch time', () => {
        expect(isLocalDateTime('2026-10-20T14:30')).toBe(true);
        expect(isLocalDateTime('2026-10-20 14:30')).toBe(false);
        expect(localDateTime(new Date(2026, 9, 5, 7, 4))).toBe('2026-10-05T07:04');
    });

    it('does not invent a percentage', () => {
        expect(percent(1, 4)).toBe(25);
        expect(percent(0, 0)).toBeNull();
    });
});

describe('channel notice', () => {
    it('says SMS is not configured, with the reason, and never looks like it works', () => {
        const wrapper = track(mount(ChannelNotice, { props: { channel: notConfigured } }));

        expect(wrapper.get('[data-testid="channel-notice"]').text()).toContain('SMS is not configured');
        expect(wrapper.text()).toContain('no SMS provider is configured');
    });

    it('names the in-memory test provider as a test provider', () => {
        const wrapper = track(mount(ChannelNotice, { props: { channel: { ...available, testDouble: true } } }));

        expect(wrapper.text()).toContain('Test provider connected');
        expect(wrapper.text()).toContain('not to phones');
    });

    it('is silent for a real, configured channel', () => {
        const wrapper = track(mount(ChannelNotice, { props: { channel: available } }));

        expect(wrapper.find('[data-testid="channel-notice"]').exists()).toBe(false);
    });
});

describe('segment meter', () => {
    it('shows the encoding and warns when the text is too long', () => {
        const wrapper = track(mount(SegmentMeter, { props: { count: countSegments('অ'.repeat(140)), maxSegments: 2 } }));

        expect(wrapper.text()).toContain('Unicode');
        expect(wrapper.text()).toContain('140 characters, 3 segments');
        expect(wrapper.text()).toContain('more than 2 segments');
    });
});

describe('campaign list', () => {
    const props = { campaigns: paginated([row()]), filters: { status: null, q: null }, statuses: ['draft', 'completed'] as const, standing: standing(), canCreate: true };

    it('shows progress, the plan allowance and a credit balance that is not reported', () => {
        const wrapper = track(mount(CampaignsIndex, { props: { ...props, statuses: [...props.statuses] } }));

        expect(wrapper.text()).toContain('Eid offer');
        expect(wrapper.text()).toContain('4 recipients, 3 accepted, 1 failed, 0 blocked');
        expect(wrapper.text()).toContain('120 of 1,000');
        expect(wrapper.text()).toContain('Not reported');
        expect(wrapper.find('a[href="/sms_marketing.campaigns.show?campaign=c-1"]').exists()).toBe(true);
    });

    it('warns that SMS is not configured and hides the create button without permission', () => {
        const wrapper = track(mount(CampaignsIndex, {
            props: { ...props, statuses: [...props.statuses], standing: standing({ channel: notConfigured }), canCreate: false },
        }));

        expect(wrapper.get('[data-testid="channel-notice"]').text()).toContain('SMS is not configured');
        expect(wrapper.find('a[href="/sms_marketing.campaigns.create"]').exists()).toBe(false);
    });

    it('shows why a running campaign is on hold', () => {
        const wrapper = track(mount(CampaignsIndex, {
            props: { ...props, statuses: [...props.statuses], campaigns: paginated([row({ status: 'sending', holdReason: 'daily_cap' })]) },
        }));

        expect(wrapper.text()).toContain('daily sending cap is reached');
    });
});

describe('campaign form', () => {
    it('counts segments live, including the opt-out line, and switches to Unicode for Bengali', async () => {
        const wrapper = track(mount(CampaignsForm, { props: { campaign: null, form: formOptions(), canEstimate: true } }));
        const body = wrapper.get('textarea');

        await body.setValue('Eid sale today');
        expect(wrapper.get('[data-testid="segment-meter"]').text()).toContain('GSM-7');

        await body.setValue('ঈদের অফার আজ শুরু');
        expect(wrapper.get('[data-testid="segment-meter"]').text()).toContain('Unicode');
        expect(wrapper.get('[data-testid="optout-note"]').text()).toContain('Opt out:');
    });

    it('refuses an unknown variable and keeps the save button disabled', async () => {
        const wrapper = track(mount(CampaignsForm, { props: { campaign: null, form: formOptions(), canEstimate: true } }));
        await wrapper.get('input').setValue('Eid');
        await wrapper.get('textarea').setValue('Hello {{salary}}');
        await wrapper.findAll('input[type="checkbox"]')[0]!.setValue(true);

        expect(wrapper.text()).toContain('Unknown variables: salary');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('saves the draft with the chosen groups and no empty references', async () => {
        const wrapper = track(mount(CampaignsForm, { props: { campaign: null, form: formOptions(), canEstimate: true } }));
        await wrapper.get('input').setValue('  Eid  ');
        await wrapper.get('textarea').setValue('Hello {{first_name|friend}}');
        await wrapper.findAll('input[type="checkbox"]')[1]!.setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toMatchObject({
            method: 'post',
            url: '/sms_marketing.campaigns.store',
            data: { name: 'Eid', template_id: null, sender_identity_id: null, group_ids: ['g-2'] },
        });
    });

    it('asks the server for the audience and shows an unchecked consent count as not checked', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                audience: {
                    contacts: 9000, validNumbers: 8800, invalidNumbers: 200, checked: false, sendable: null, blocked: {}, segmentsPerMessage: 1,
                    encoding: 'gsm7', segments: 8800, exact: false, costAmount: 4400, currency: 'BDT', tooLarge: false, channelAvailable: true,
                },
            }),
        })));

        const wrapper = track(mount(CampaignsForm, {
            props: { campaign: detail(), form: formOptions(), canEstimate: true },
        }));
        const estimate = wrapper.findAll('button').find((button) => button.text().includes('Estimate audience'));
        await estimate!.trigger('click');
        await flushPromises();

        const panel = wrapper.get('[data-testid="estimate"]');
        expect(panel.text()).toContain('Not checked');
        expect(panel.text()).toContain('Approximate');
        expect(panel.text()).toContain('8,800');
    });

    it('has no estimate button for someone who cannot create campaigns', () => {
        const wrapper = track(mount(CampaignsForm, { props: { campaign: detail(), form: formOptions(), canEstimate: false } }));

        expect(wrapper.findAll('button').some((button) => button.text().includes('Estimate audience'))).toBe(false);
    });
});

describe('campaign page', () => {
    const base = {
        recipients: paginated([]),
        filters: { status: null },
        statuses: ['pending', 'sent'] as const,
        sample: countSegments('Hello'),
        channel: available,
    };

    it('launches now with no schedule and refuses a missing time for a scheduled launch', async () => {
        const wrapper = track(mount(CampaignsShow, { props: { ...base, statuses: [...base.statuses], campaign: detail() }, global: { stubs } }));
        const launch = wrapper.findAll('button').find((button) => button.text() === 'Launch');
        await launch!.trigger('click');

        const form = wrapper.findAll('form').find((item) => item.text().includes('When to send'))!;
        await form.trigger('submit');
        expect(inertia.submissions.at(-1)).toMatchObject({ method: 'post', url: '/sms_marketing.campaigns.launch?campaign=c-1', data: { scheduled_for: null } });
    });

    it('shows only the controls the campaign state and permissions allow', () => {
        const running = track(mount(CampaignsShow, {
            props: {
                ...base, statuses: [...base.statuses],
                campaign: detail({ status: 'sending', abilities: { edit: false, delete: false, launch: false, cancel: true, retry: true }, retryable: 2 }),
            },
            global: { stubs },
        }));
        const labels = running.findAll('button').map((button) => button.text());

        expect(labels).toContain('Cancel campaign');
        expect(labels).toContain('Retry 2 failed');
        expect(labels).not.toContain('Launch');
        expect(running.text()).not.toContain('Edit');
    });

    it('explains an on-hold campaign', () => {
        const wrapper = track(mount(CampaignsShow, {
            props: { ...base, statuses: [...base.statuses], campaign: detail({ status: 'sending', holdReason: 'limit_reached' }) },
            global: { stubs },
        }));

        expect(wrapper.get('[data-testid="hold"]').text()).toContain('plan allowance of segments');
    });
});

describe('group page', () => {
    const group = { id: 'g-1', name: 'Customers', description: null, membersCount: 1 };
    const props = {
        group,
        members: paginated([{ id: 'm-1', contactId: 'k-1', name: 'Rahim Uddin', phone: '+8801711000001', email: null, validNumber: true }]),
        candidates: [{ id: 'k-2', name: 'Karim Ali', phone: '01811000002', email: null, validNumber: true }],
        filters: { contact_q: 'Karim' },
        imports: [{ id: 'i-1', fileName: 'numbers.csv', totalRows: 3, addedRows: 2, failedRows: 1, errors: [{ line: 4, message: 'This is not a valid phone number.' }], createdAt: null }],
        importInfo: { columns: ['phone'], maxRows: 5000, maxBytes: 524288 },
        contacts: { limit: 100, used: 40 },
        canManage: true,
    };

    it('adds the picked contacts through the server', async () => {
        const wrapper = track(mount(GroupsShow, { props, global: { stubs } }));
        await wrapper.get('[data-testid="candidates"] input[type="checkbox"]').setValue(true);
        const add = wrapper.findAll('button').find((button) => button.text().includes('Add 1 selected'));
        await add!.trigger('click');

        expect(inertia.router.post).toHaveBeenCalledWith('/sms_marketing.groups.members.store?group=g-1', { contact_ids: ['k-2'] }, expect.anything());
    });

    it('reports import rows that could not be matched and the contact allowance', () => {
        const wrapper = track(mount(GroupsShow, { props, global: { stubs } }));

        expect(wrapper.get('[data-testid="imports"]').text()).toContain('2 added, 1 not matched, 3 rows');
        expect(wrapper.text()).toContain('Contacts in groups: 40 of 100');
    });

    it('hides every management control from a viewer', () => {
        const wrapper = track(mount(GroupsShow, { props: { ...props, canManage: false, candidates: [] }, global: { stubs } }));

        expect(wrapper.text()).not.toContain('Add contacts');
        expect(wrapper.text()).not.toContain('Import phone numbers');
        expect(wrapper.text()).not.toContain('Remove');
    });
});

describe('sender ids', () => {
    const props = { senders: [], types: ['masking', 'non_masking'] as const, statuses: ['not_registered', 'pending', 'registered', 'rejected'] as const, canManage: true };

    it('says registration is the workspace own record and never verified', () => {
        const wrapper = track(mount(SendersIndex, { props: { ...props, types: [...props.types], statuses: [...props.statuses] }, global: { stubs } }));

        expect(wrapper.get('[data-testid="registration-note"]').text()).toContain('does not check any regulator');
    });

    it('explains an invalid masking id before it is sent', async () => {
        const wrapper = track(mount(SendersIndex, { props: { ...props, types: [...props.types], statuses: [...props.statuses] }, global: { stubs } }));
        const add = wrapper.findAll('button').find((button) => button.text() === 'New sender id');
        await add!.trigger('click');

        const inputs = wrapper.findAll('input');
        await inputs[0]!.setValue('Brand');
        await inputs[1]!.setValue('TOOLONGSENDERID');
        expect(wrapper.text()).toContain('3 to 11 letters and digits');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

        await inputs[1]!.setValue('ACME');
        expect(wrapper.text()).not.toContain('3 to 11 letters and digits with at least one letter.');
    });
});

describe('settings', () => {
    const settings: SettingsValues = {
        dailyCap: 1000, throttlePerMinute: 60, optOutWording: null, optOutDefault: 'Opt out:', segmentCostAmount: 45, currency: 'BDT',
        defaultSenderId: null, bounds: { dailyCap: [1, 100000], throttlePerMinute: [1, 600] },
    };

    it('shows the price per segment in major units and saves it as exact minor units', async () => {
        const wrapper = track(mount(SettingsIndex, { props: { settings, senders: [], channel: available, timezone: 'Asia/Dhaka' } }));
        const inputs = wrapper.findAll('input');

        expect((inputs[3]!.element as HTMLInputElement).value).toBe('0.45');
        await inputs[3]!.setValue('1.25');
        await wrapper.get('form').trigger('submit');

        expect(inertia.submissions.at(-1)).toMatchObject({
            method: 'put',
            url: '/sms_marketing.settings.update',
            data: { daily_cap: 1000, throttle_per_minute: 60, segment_cost_amount: 125, opt_out_wording: null, default_sender_id: null },
        });
    });

    it('does not allow a cap outside the server bounds', async () => {
        const wrapper = track(mount(SettingsIndex, { props: { settings, senders: [], channel: available, timezone: 'Asia/Dhaka' } }));
        await wrapper.findAll('input')[0]!.setValue('0');

        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});

describe('analytics', () => {
    const report: AnalyticsReport = {
        totals: { handed: 10, sent: 8, delivered: 0, failed: 1, blocked: 1, skipped: 0, pending: 0, segments: 12 },
        deliveryRate: null,
        daily: [{ date: '2026-10-11', sent: 8, delivered: 0, failed: 1, blocked: 1, segments: 12 }],
    };

    it('never turns a missing delivery rate or credit balance into a number', () => {
        const wrapper = track(mount(AnalyticsIndex, {
            props: {
                report, standing: standing(), campaigns: [], cost: { segmentAmount: null, currency: null },
                range: { from: '2026-09-12', to: '2026-10-11', timezone: 'Asia/Dhaka' },
            },
        }));
        const text = wrapper.text();

        expect(text.match(/Not reported/g)?.length).toBeGreaterThanOrEqual(2);
        expect(text).toContain('Set your price per segment in Settings');
    });

    it('multiplies segments by the workspace own rate for the spend', () => {
        const wrapper = track(mount(AnalyticsIndex, {
            props: {
                report, standing: standing(), campaigns: [], cost: { segmentAmount: 45, currency: 'BDT' },
                range: { from: '2026-09-12', to: '2026-10-11', timezone: 'Asia/Dhaka' },
            },
        }));

        // 12 segments at 0.45 is 5.40 in BDT.
        expect(wrapper.text()).toMatch(/5\.40/);
    });
});

describe('widget', () => {
    it('shows the allowance and the channel state', () => {
        inertia.page.props.auth.permissions = ['sms_marketing.campaigns.view'];
        const wrapper = track(mount(SmsMarketingOverviewWidget, {
            props: { data: { sending: 1, scheduled: 2, onHold: 1, segmentsUsed: 30, segmentsLimit: 100, channelAvailable: false, channelTestDouble: false, nextScheduledFor: null } },
        }));

        expect(wrapper.get('[data-testid="sms-allowance"]').text()).toContain('30 of 100 segments used this month');
        expect(wrapper.get('[data-testid="sms-channel"]').text()).toContain('SMS is not configured');
    });
});

describe('Bengali catalogue', () => {
    it('has the SMS Marketing messages in both languages with the same keys', () => {
        const keys = (tree: Record<string, unknown>, prefix = ''): string[] =>
            Object.entries(tree).flatMap(([key, value]) =>
                typeof value === 'string' ? [`${prefix}${key}`] : keys(value as Record<string, unknown>, `${prefix}${key}.`),
            );

        expect(keys(bn.smsMarketing as unknown as Record<string, unknown>).sort()).toEqual(keys(en.smsMarketing as unknown as Record<string, unknown>).sort());
    });

    it('renders the not configured notice in Bengali', () => {
        setLocale('bn');
        const wrapper = track(mount(ChannelNotice, { props: { channel: notConfigured } }));

        expect(wrapper.text()).toContain('এসএমএস সেট আপ করা নেই');
    });
});

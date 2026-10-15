import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'en', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            auth: { permissions: ['telephony.calls.make', 'telephony.calls.view', 'telephony.settings.manage'] },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (props, { slots, attrs }) => () => h('a', { href: props.href, ...attrs }, slots.default?.()) }),
    Head: defineComponent({ setup: () => () => null }),
    usePage: () => inertia.page,
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    useForm: vi.fn(),
}));

vi.mock('@/Core/lib/route', () => ({ route: (name: string) => `/${name}`, isCurrentRoute: () => false }));

// The softphone library is never loaded in these tests: a dialer that is not set up must not even try.
const jssip = vi.hoisted(() => ({ constructed: 0 }));
vi.mock('jssip', () => ({
    UA: class {
        constructor() {
            jssip.constructed++;
        }
    },
    WebSocketInterface: class {},
}));

import { setLocale } from '@/Core/i18n';
import { displayPhone, formatDuration, normalizePhone } from '@/Modules/Telephony/lib/phone';
import { reportForCause } from '@/Modules/Telephony/lib/softphone';
import Dialer from '@/Modules/Telephony/Pages/Dialer.vue';

describe('telephony phone numbers', () => {
    it('reads a Bangladeshi number in any common spelling, Bengali digits too', () => {
        for (const raw of ['01712345678', '1712345678', '8801712345678', '+880 1712-345678', '00880 1712 345678', '০১৭১২৩৪৫৬৭৮']) {
            expect(normalizePhone(raw), raw).toBe('+8801712345678');
        }
    });

    it('refuses what is not a number, and guesses no foreign country code', () => {
        expect(normalizePhone('')).toBeNull();
        expect(normalizePhone('call me')).toBeNull();
        expect(normalizePhone('12')).toBeNull();
        expect(normalizePhone('0171234')).toBeNull();
        expect(normalizePhone('+8801012345678')).toBeNull();
        expect(normalizePhone('+44 20 7946 0958')).toBe('+442079460958');
    });

    it('shows a Bangladeshi mobile the local way and durations as minutes', () => {
        expect(displayPhone('+8801712345678')).toBe('01712-345678');
        expect(displayPhone('+442079460958')).toBe('+442079460958');
        expect(formatDuration(65)).toBe('1:05');
        expect(formatDuration(3725)).toBe('1:02:05');
    });
});

describe('telephony call outcomes', () => {
    it('maps why an outgoing call failed, and leaves an unanswered incoming call to the server', () => {
        expect(reportForCause('Busy', true)).toBe('busy');
        expect(reportForCause('No Answer', true)).toBe('no_answer');
        expect(reportForCause('Canceled', true)).toBe('no_answer');
        expect(reportForCause('User Denied Media Access', true)).toBe('failed');
        expect(reportForCause('Canceled', false)).toBe('completed');
    });
});

describe('telephony dialer', () => {
    const props = (setup: Partial<Record<string, unknown>> = {}) => ({
        setup: { workspaceSoftphone: false, workspaceBridge: false, extension: null, hasPassword: false, bridgePhone: null, ...setup },
        prefill: { number: null, contact: null },
        recent: [],
        canViewLog: true,
        canManageSettings: true,
    });

    it('says plainly that calling is not set up, and connects nothing', () => {
        setLocale('en');
        const wrapper = mount(Dialer, { props: props() });

        expect(wrapper.find('[data-testid="dialer-not-configured"]').text()).toContain('This workspace has not connected its phone system.');
        expect(wrapper.find('[data-testid="call-softphone"]').exists()).toBe(false);
        expect(jssip.constructed).toBe(0);
    });

    it('offers a bridge call alone when only the PBX API is set up', () => {
        setLocale('en');
        const wrapper = mount(Dialer, { props: props({ workspaceBridge: true, extension: '101', bridgePhone: '+8801711000009' }) });

        expect(wrapper.find('[data-testid="call-bridge"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="call-softphone"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Your phone 01711-000009 rings first.');
        expect(jssip.constructed).toBe(0);
    });

    it('is complete in Bengali', () => {
        setLocale('bn');
        const wrapper = mount(Dialer, { props: props({ workspaceBridge: true, extension: '101' }) });

        expect(wrapper.text()).not.toMatch(/telephony\.[a-z]/);
        expect(wrapper.text()).toContain('আমার ফোন দিয়ে কল করুন');
        setLocale('en');
    });
});

import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            app: { locale: 'bn', numerals: 'latn', timezone: 'Asia/Dhaka', currency: 'BDT' },
            voice: { maxSeconds: 30, maxBytes: 2_097_152, autoRun: false } as { maxSeconds: number; maxBytes: number; autoRun: boolean } | null,
        },
    },
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => inertia.page,
    router: { visit: inertia.visit, post: vi.fn() },
}));

vi.mock('@/Core/lib/route', () => ({
    route: (name: string) => `/${name}`,
}));

import CommandPalette, { type Command } from '@/Core/Components/Shell/CommandPalette.vue';
import VoiceButton from '@/Core/Components/VoiceButton.vue';
import { extensionFor, matchSpoken, normaliseSpoken, preferredMimeType, transcribeClip } from '@/Core/lib/voice';

const entries = [
    { id: 'nav:contacts', label: 'Contacts' },
    { id: 'nav:settings', label: 'Settings' },
    { id: 'nav:aiSettings', label: 'AI settings' },
    { id: 'nav:invoices', label: 'Invoices' },
    { id: 'nav:bnContacts', label: 'কন্টাক্ট' },
];

describe('matching what was said', () => {
    it('normalises case, punctuation and the Bengali full stop', () => {
        expect(normaliseSpoken('  Open  Contacts, please! ')).toBe('open contacts please');
        expect(normaliseSpoken('কন্টাক্ট খোলো।')).toBe('কন্টাক্ট খোলো');
    });

    it('finds the page named inside a sentence', () => {
        expect(matchSpoken(entries, 'Open contacts.').map((entry) => entry.id)).toEqual(['nav:contacts']);
        expect(matchSpoken(entries, 'কন্টাক্ট খোলো।').map((entry) => entry.id)).toEqual(['nav:bnContacts']);
    });

    it('prefers the longest page name when several are said', () => {
        expect(matchSpoken(entries, 'go to AI settings').map((entry) => entry.id)).toEqual(['nav:aiSettings']);
    });

    it('matches a whole word only, then falls back to labels containing a short utterance', () => {
        // "invoice" is not the word "invoices", but it is part of that label.
        expect(matchSpoken(entries, 'invoice').map((entry) => entry.id)).toEqual(['nav:invoices']);
        expect(matchSpoken(entries, 'something else entirely')).toEqual([]);
        expect(matchSpoken(entries, ' ... ')).toEqual([]);
    });
});

describe('recording formats', () => {
    it('names the file by the container', () => {
        expect(extensionFor('audio/webm;codecs=opus')).toBe('webm');
        expect(extensionFor('audio/ogg;codecs=opus')).toBe('ogg');
        expect(extensionFor('audio/mp4')).toBe('mp4');
        expect(extensionFor('')).toBe('webm');
    });

    it('takes the first container the recorder supports', () => {
        expect(preferredMimeType((type) => type === 'audio/mp4')).toBe('audio/mp4');
        expect(preferredMimeType(() => false)).toBeUndefined();
        expect(
            preferredMimeType(() => {
                throw new Error('not supported');
            }),
        ).toBeUndefined();
    });
});

describe('sending a clip', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the clip and the language and returns the text', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ text: 'কন্টাক্ট' }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await transcribeClip(new Blob(['x'], { type: 'audio/webm;codecs=opus' }), 'bn');

        expect(result).toEqual({ ok: true, text: 'কন্টাক্ট' });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/ai.voice.transcribe');
        expect(init.method).toBe('POST');
        const body = init.body as FormData;
        expect(body.get('language')).toBe('bn');
        expect((body.get('audio') as File).name).toBe('speech.webm');
    });

    it('passes on the server reason for a refusal and the field error for a rejected file', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ state: 'limit_reached', message: 'Allowance used.' }), { status: 403 })));
        expect(await transcribeClip(new Blob(['x']), 'en')).toEqual({ ok: false, status: 403, message: 'Allowance used.' });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Invalid.', errors: { audio: ['Too big.'] } }), { status: 422 })));
        expect(await transcribeClip(new Blob(['x']), 'en')).toEqual({ ok: false, status: 422, message: 'Too big.' });

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
        expect(await transcribeClip(new Blob(['x']), 'en')).toEqual({ ok: false, status: 0, message: null });
    });
});

/** A recorder that "hears" one chunk and reports it when stopped. */
class FakeRecorder extends EventTarget {
    static isTypeSupported = (type: string) => type === 'audio/webm;codecs=opus';
    state: 'inactive' | 'recording' = 'inactive';
    mimeType = 'audio/webm;codecs=opus';

    start(): void {
        this.state = 'recording';
    }

    stop(): void {
        this.state = 'inactive';
        const data = new Event('dataavailable') as Event & { data: Blob };
        data.data = new Blob(['voice'], { type: this.mimeType });
        this.dispatchEvent(data);
        this.dispatchEvent(new Event('stop'));
    }
}

describe('the microphone', () => {
    const stopTrack = vi.fn();

    beforeEach(() => {
        inertia.page.props.voice = { maxSeconds: 30, maxBytes: 2_097_152, autoRun: false };
        vi.stubGlobal('MediaRecorder', FakeRecorder);
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [{ stop: stopTrack }] }) },
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        stopTrack.mockReset();
    });

    it('is not shown when the workspace does not offer voice', () => {
        inertia.page.props.voice = null;

        expect(mount(VoiceButton).find('[data-testid="voice"]').exists()).toBe(false);
    });

    it('is not shown when the browser cannot record', () => {
        vi.stubGlobal('MediaRecorder', undefined);

        expect(mount(VoiceButton).find('[data-testid="voice"]').exists()).toBe(false);
    });

    it('records, stops, sends the clip and emits what was heard', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ text: 'Contacts' }), { status: 200 })));
        const wrapper = mount(VoiceButton);

        await wrapper.get('[data-testid="voice-start"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="voice-status"]').text()).toContain('30');

        await wrapper.get('[data-testid="voice-stop"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('transcript')).toEqual([['Contacts']]);
        expect(stopTrack).toHaveBeenCalled();
        expect(wrapper.find('[data-testid="voice-start"]').exists()).toBe(true);
    });

    it('says when the microphone was refused, and sends nothing', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn().mockRejectedValue(new DOMException('denied', 'NotAllowedError')) },
        });
        const wrapper = mount(VoiceButton);

        await wrapper.get('[data-testid="voice-start"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="voice-status"]').text()).not.toBe('');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.emitted('transcript')).toBeUndefined();
    });

    it('shows the server reason when transcription is refused', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ state: 'disabled', message: 'Switched off.' }), { status: 403 })));
        const wrapper = mount(VoiceButton);

        await wrapper.get('[data-testid="voice-start"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="voice-stop"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="voice-status"]').text()).toBe('Switched off.');
        expect(wrapper.emitted('transcript')).toBeUndefined();
    });
});

describe('the command palette', () => {
    const VoiceStub = defineComponent({ name: 'VoiceButton', emits: ['transcript'], setup: () => () => h('span') });
    const signOut = vi.fn();

    const sections = [
        {
            key: 'apps',
            label: 'Apps',
            items: [
                { key: 'contacts', label: 'Contacts', href: '/contacts', icon: 'users', current: false },
                { key: 'invoices', label: 'Invoices', href: '/invoices', icon: 'receipt', current: false },
            ],
        },
    ];
    const commands: Command[] = [{ id: 'action:sign-out', group: 'Actions', label: 'Sign out', perform: signOut }];

    function mountPalette() {
        return mount(CommandPalette, {
            props: { sections, commands, open: true },
            global: { stubs: { VoiceButton: VoiceStub } },
            attachTo: document.body,
        });
    }

    beforeEach(() => {
        inertia.visit.mockReset();
        signOut.mockReset();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('puts what was said in the box and waits for Enter by default', async () => {
        inertia.page.props.voice = { maxSeconds: 30, maxBytes: 1, autoRun: false };
        const wrapper = mountPalette();
        await nextTick();

        wrapper.findComponent(VoiceStub).vm.$emit('transcript', 'Open contacts');
        await nextTick();

        expect((document.querySelector('input[role="combobox"]') as HTMLInputElement).value).toBe('Open contacts');
        expect(document.querySelectorAll('[role="option"]')).toHaveLength(1);
        expect(inertia.visit).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('opens a single matching page without Enter when the member chose that', async () => {
        inertia.page.props.voice = { maxSeconds: 30, maxBytes: 1, autoRun: true };
        const wrapper = mountPalette();
        await nextTick();

        wrapper.findComponent(VoiceStub).vm.$emit('transcript', 'Open contacts');
        await nextTick();

        expect(inertia.visit).toHaveBeenCalledWith('/contacts');
        wrapper.unmount();
    });

    it('never runs an action without Enter, even with the preference on', async () => {
        inertia.page.props.voice = { maxSeconds: 30, maxBytes: 1, autoRun: true };
        const wrapper = mountPalette();
        await nextTick();

        wrapper.findComponent(VoiceStub).vm.$emit('transcript', 'sign out');
        await nextTick();

        expect(document.querySelectorAll('[role="option"]')).toHaveLength(1);
        expect(signOut).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});

describe('templates', () => {
    it('use no v-html in the voice control', () => {
        for (const file of ['resources/js/Core/Components/VoiceButton.vue', 'resources/js/Core/Components/Shell/CommandPalette.vue']) {
            expect(readFileSync(file, 'utf8')).not.toContain('v-html');
        }
    });
});

import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createPoller } from '@/Modules/Communication/Components/inboxChat';
import {
    authorizeChannel,
    createInboxRealtime,
    INBOX_EVENT,
    type InboxRealtimeConfig,
    parseSignal,
    type RealtimeConnection,
    useInboxRealtime,
} from '@/Modules/Communication/Components/useInboxRealtime';

const ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const OTHER = '01JABCDEFGHJKMNPQRSTVWXYZ1';

const CONFIG: InboxRealtimeConfig = { key: 'app-key', host: 'localhost', port: 8080, scheme: 'http', channel: 'tenant.01T.inbox', authEndpoint: '/broadcasting/auth' };

/** A connection the test drives by hand. */
function fakeConnection() {
    const state: { onState?: (state: string) => void; channel?: string; event?: string; handlers?: { event: (payload: unknown) => void; subscribed: () => void; error: () => void }; closed: number } = { closed: 0 };
    const connection: RealtimeConnection = {
        onState: (callback) => { state.onState = callback; },
        subscribe: (channel, event, handlers) => { state.channel = channel; state.event = event; state.handlers = handlers; },
        close: () => { state.closed++; },
    };

    return { connection, state, connector: vi.fn(async () => connection) };
}

const flushPromises = async (): Promise<void> => { await Promise.resolve(); await Promise.resolve(); };

describe('inbox realtime', () => {
    beforeEach(() => { vi.useFakeTimers(); });
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('accepts only a source and a ULID as a signal', () => {
        expect(parseSignal({ source: 'support.live_chat', id: ID })).toEqual({ source: 'support.live_chat', id: ID });
        expect(parseSignal({ source: 'support.live_chat', id: ID, text: 'ignored' })).toEqual({ source: 'support.live_chat', id: ID });
        expect(parseSignal({ source: 'Support', id: ID })).toBeNull();
        expect(parseSignal({ source: 'support.live_chat', id: 'x' })).toBeNull();
        expect(parseSignal('support.live_chat')).toBeNull();
        expect(parseSignal(null)).toBeNull();
    });

    it('does nothing at all without a configuration', async () => {
        const { connector } = fakeConnection();
        const realtime = createInboxRealtime({ config: () => null, onChange: vi.fn(), connector });

        realtime.start();
        await flushPromises();

        expect(connector).not.toHaveBeenCalled();
        expect(realtime.connected.value).toBe(false);
    });

    it('connects with the configuration, subscribes to the channel and is connected once subscribed', async () => {
        const { connector, state } = fakeConnection();
        const realtime = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector });

        realtime.start();
        await flushPromises();

        expect(connector).toHaveBeenCalledWith(CONFIG);
        expect(state.channel).toBe('tenant.01T.inbox');
        expect(state.event).toBe(INBOX_EVENT);

        state.onState!('connected');
        expect(realtime.connected.value).toBe(false);
        state.handlers!.subscribed();
        expect(realtime.connected.value).toBe(true);
    });

    it('gathers signals for a moment and reports each conversation once', async () => {
        const { connector, state } = fakeConnection();
        const onChange = vi.fn();
        const realtime = createInboxRealtime({ config: () => CONFIG, onChange, connector, debounce: 300 });

        realtime.start();
        await flushPromises();
        state.onState!('connected');
        state.handlers!.subscribed();

        state.handlers!.event({ source: 'whatsapp_marketing.conversations', id: ID });
        state.handlers!.event({ source: 'whatsapp_marketing.conversations', id: ID });
        state.handlers!.event({ source: 'support.tickets', id: OTHER });
        state.handlers!.event({ nonsense: true });
        vi.advanceTimersByTime(299);
        expect(onChange).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith([{ source: 'whatsapp_marketing.conversations', id: ID }, { source: 'support.tickets', id: OTHER }]);

        // Only garbage: nothing to report.
        state.handlers!.event({ source: 'x', id: 'y' });
        vi.advanceTimersByTime(1000);
        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it('gives up quietly when the server is not there, and keeps the page on polling', async () => {
        for (const failure of ['unavailable', 'failed', 'error']) {
            const { connector, state } = fakeConnection();
            const warn = vi.fn();
            const realtime = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector, warn });

            realtime.start();
            await flushPromises();
            state.onState!('connecting');
            state.onState!(failure);
            state.onState!(failure);

            expect(warn).toHaveBeenCalledTimes(1);
            expect(state.closed).toBe(1);
            expect(realtime.connected.value).toBe(false);
        }
    });

    it('gives up when the subscription is refused or the client cannot load', async () => {
        const { connector, state } = fakeConnection();
        const warn = vi.fn();
        const refused = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector, warn });

        refused.start();
        await flushPromises();
        state.onState!('connected');
        state.handlers!.error();

        expect(warn).toHaveBeenCalledTimes(1);
        expect(state.closed).toBe(1);
        expect(refused.connected.value).toBe(false);

        const broken = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector: () => Promise.reject(new Error('offline')), warn });
        broken.start();
        await flushPromises();
        expect(warn).toHaveBeenCalledTimes(2);
        expect(broken.connected.value).toBe(false);
    });

    it('falls back while a working connection is down and asks for everything once it is back', async () => {
        const { connector, state } = fakeConnection();
        const onResync = vi.fn();
        const warn = vi.fn();
        const realtime = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), onResync, connector, warn });

        realtime.start();
        await flushPromises();
        state.onState!('connected');
        state.handlers!.subscribed();
        expect(realtime.connected.value).toBe(true);

        state.onState!('unavailable');
        expect(realtime.connected.value).toBe(false);
        expect(warn).not.toHaveBeenCalled();
        expect(state.closed).toBe(0);

        state.onState!('connected');
        expect(realtime.connected.value).toBe(true);
        expect(onResync).toHaveBeenCalledTimes(1);
    });

    it('closes on stop and ignores anything that arrives afterwards', async () => {
        const { connector, state } = fakeConnection();
        const onChange = vi.fn();
        const realtime = createInboxRealtime({ config: () => CONFIG, onChange, connector });

        realtime.start();
        await flushPromises();
        state.onState!('connected');
        state.handlers!.subscribed();
        state.handlers!.event({ source: 'support.tickets', id: ID });
        realtime.stop();

        expect(state.closed).toBe(1);
        expect(realtime.connected.value).toBe(false);
        state.handlers!.event({ source: 'support.tickets', id: ID });
        vi.advanceTimersByTime(5000);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('closes a connection that opens after the page already left', async () => {
        const { connector, state } = fakeConnection();
        const realtime = createInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector });

        realtime.start();
        realtime.stop();
        await flushPromises();

        expect(state.closed).toBe(1);
        expect(state.channel).toBeUndefined();
    });

    it('starts with the page and stops when it unmounts', async () => {
        const { connector, state } = fakeConnection();
        const Host = defineComponent({
            setup() {
                useInboxRealtime({ config: () => CONFIG, onChange: vi.fn(), connector });

                return () => h('div');
            },
        });

        const wrapper = mount(Host);
        await flushPromises();
        expect(connector).toHaveBeenCalledTimes(1);

        wrapper.unmount();
        expect(state.closed).toBe(1);
    });

    it('asks the workspace to sign the subscription with the session and the XSRF header', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ auth: 'app-key:sig' }), { status: 200 }));

        await expect(authorizeChannel('/broadcasting/auth', '1.2', 'private-tenant.01T.inbox')).resolves.toEqual({ auth: 'app-key:sig' });

        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe('/broadcasting/auth');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('same-origin');
        expect((init?.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
        expect(JSON.parse(String(init?.body))).toEqual({ socket_id: '1.2', channel_name: 'private-tenant.01T.inbox' });

        fetchMock.mockResolvedValue(new Response('', { status: 403 }));
        await expect(authorizeChannel('/broadcasting/auth', '1.2', 'private-tenant.01T.inbox')).rejects.toThrow('403');
    });
});

describe('poller under realtime', () => {
    beforeEach(() => { vi.useFakeTimers(); });
    afterEach(() => { vi.useRealTimers(); });

    it('asks once more after a running request when something changed meanwhile, never while hidden', () => {
        const finishers: Array<() => void> = [];
        const tick = vi.fn((done: () => void) => { finishers.push(done); });
        let hidden = false;
        const poller = createPoller({ interval: 1000, tick, isHidden: () => hidden });

        poller.request();
        expect(tick).toHaveBeenCalledTimes(1);

        // Two signals while it runs: one more request after it, not two.
        poller.request();
        poller.request();
        expect(tick).toHaveBeenCalledTimes(1);
        finishers.shift()!();
        expect(tick).toHaveBeenCalledTimes(2);
        finishers.shift()!();
        expect(tick).toHaveBeenCalledTimes(2);

        hidden = true;
        poller.request();
        expect(tick).toHaveBeenCalledTimes(2);
    });

    it('changes its pace while running', () => {
        const tick = vi.fn((done: () => void) => done());
        const poller = createPoller({ interval: 1000, tick, isHidden: () => false });

        poller.start();
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(1);

        poller.retime(60_000);
        vi.advanceTimersByTime(59_000);
        expect(tick).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(2);

        poller.retime(1000);
        vi.advanceTimersByTime(1000);
        expect(tick).toHaveBeenCalledTimes(3);
        expect(poller.running).toBe(true);

        poller.stop();
        poller.retime(500);
        expect(poller.running).toBe(false);
    });
});

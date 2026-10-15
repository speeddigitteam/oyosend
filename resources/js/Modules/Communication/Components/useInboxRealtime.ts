/**
 * Realtime for the unified inbox (ADR 0035): while the platform runs its websocket server (Reverb), the page hears
 * "conversation X of source Y changed" on the workspace's private inbox channel and refreshes at once, instead of at
 * its next poll. The signal carries no content: the page asks the inbox's own endpoints again, which decide what the
 * member may see. Without realtime (`config` null), or when the server cannot be reached, nothing here runs and the
 * page keeps polling as before. The websocket client is loaded only when realtime is on.
 */
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/** What the server tells the page (the inbox's `realtime` prop). Public values only: never the secret. */
export interface InboxRealtimeConfig {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
    /** The channel name without `private-`, e.g. `tenant.{id}.inbox`. */
    channel: string;
    /** Same-origin route that signs the subscription (`core.realtime.auth`). */
    authEndpoint: string;
}

/** One "this conversation changed" signal. */
export interface InboxSignal {
    source: string;
    id: string;
}

/** A websocket connection as this composable uses it; small enough to replace in a test. */
export interface RealtimeConnection {
    /**
     * Reports each connection state (`connecting`, `connected`, `unavailable`, `failed`, `disconnected`) and `error`
     * when the socket reports one.
     */
    onState(callback: (state: string) => void): void;
    subscribe(channel: string, event: string, handlers: { event: (payload: unknown) => void; subscribed: () => void; error: () => void }): void;
    close(): void;
}

export type RealtimeConnector = (config: InboxRealtimeConfig) => Promise<RealtimeConnection>;

/** The event the server broadcasts (`InboxChanged::broadcastAs()`); the leading dot means "not namespaced". */
export const INBOX_EVENT = '.inbox.changed';

/** How long signals are gathered before the page refreshes once for all of them, in milliseconds. */
export const SIGNAL_DEBOUNCE = 400;

const SOURCE = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;
const ID = /^[0-9A-Za-z]{26}$/;

/** The signal in a broadcast payload, or null for anything else. */
export function parseSignal(payload: unknown): InboxSignal | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const { source, id } = payload as Record<string, unknown>;

    return typeof source === 'string' && typeof id === 'string' && SOURCE.test(source) && ID.test(id) ? { source, id } : null;
}

/** The session's XSRF token from its cookie, as the app's other background requests send it. */
function xsrfHeader(): Record<string, string> {
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]!) } : {};
}

/** Asks the workspace to sign a subscription: same origin, session cookie and XSRF header, like any background POST. */
export async function authorizeChannel(endpoint: string, socketId: string, channelName: string): Promise<{ auth: string }> {
    const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...xsrfHeader() },
        body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    });

    if (!response.ok) {
        throw new Error(`Channel authorization was refused (${response.status}).`);
    }

    const body = (await response.json()) as { auth?: unknown };

    if (typeof body.auth !== 'string') {
        throw new Error('Channel authorization was not understood.');
    }

    return { auth: body.auth };
}

/** The real connection: Laravel Echo over pusher-js to Reverb, loaded on first use only. */
export const echoConnector: RealtimeConnector = async (config) => {
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);

    const echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        withoutInterceptors: true,
        Pusher,
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                authorizeChannel(config.authEndpoint, socketId, channelName).then(
                    (data) => callback(null, data),
                    (error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null),
                );
            },
        },
    });

    let channelName: string | null = null;

    return {
        onState(callback): void {
            echo.connector.pusher.connection.bind('state_change', (states: { current: string }) => callback(states.current));
            echo.connector.pusher.connection.bind('error', () => callback('error'));
        },
        subscribe(channel, event, handlers): void {
            channelName = channel;
            echo.private(channel).listen(event, handlers.event).subscribed(handlers.subscribed).error(handlers.error);
        },
        close(): void {
            if (channelName !== null) {
                echo.leave(channelName);
            }

            echo.disconnect();
        },
    };
};

export interface InboxRealtimeOptions {
    /** The page's `realtime` prop; null (or absent) keeps everything off. */
    config: () => InboxRealtimeConfig | null | undefined;
    /** Signals gathered over {@see SIGNAL_DEBOUNCE} ms, each conversation once. */
    onChange: (signals: InboxSignal[]) => void;
    /** The connection came back after a drop: signals may have been missed, so refresh everything once. */
    onResync?: () => void;
    connector?: RealtimeConnector;
    debounce?: number;
    warn?: (message: string) => void;
}

export interface InboxRealtime {
    /** True while subscribed to the channel over a live connection; the page polls slowly then. */
    readonly connected: Ref<boolean>;
    start(): void;
    stop(): void;
}

const WARNING = 'Realtime inbox updates are not available; the inbox keeps refreshing on its own.';

/**
 * The realtime connection of one inbox page, without Vue's lifecycle (see {@see useInboxRealtime}).
 *
 * - No config: nothing happens, `connected` stays false.
 * - The server cannot be reached before it ever connected, or the subscription is refused: one quiet warning, the
 *   connection is closed (no endless retries filling the console), `connected` stays false and polling carries on.
 * - After a drop of a connection that worked, the client reconnects by itself; meanwhile `connected` is false, and when
 *   it is back `onResync` runs once.
 */
export function createInboxRealtime(options: InboxRealtimeOptions): InboxRealtime {
    const connected = ref(false);
    const connector = options.connector ?? echoConnector;
    const debounce = options.debounce ?? SIGNAL_DEBOUNCE;
    const warn = options.warn ?? ((message: string) => console.warn(message));

    let connection: RealtimeConnection | null = null;
    let stopped = true;
    let generation = 0;
    let socketUp = false;
    let subscribed = false;
    let everConnected = false;
    let lost = false;
    let warned = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const pending = new Map<string, InboxSignal>();

    const update = (): void => {
        connected.value = socketUp && subscribed;
    };

    const flush = (): void => {
        timer = null;

        if (pending.size === 0) {
            return;
        }

        const signals = [...pending.values()];
        pending.clear();
        options.onChange(signals);
    };

    const giveUp = (): void => {
        if (!warned) {
            warned = true;
            warn(WARNING);
        }

        close();
    };

    function close(): void {
        generation++;
        connection?.close();
        connection = null;
        socketUp = false;
        subscribed = false;
        update();

        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        pending.clear();
    }

    return {
        connected,
        start(): void {
            const config = options.config();

            if (!stopped || !config) {
                return;
            }

            stopped = false;
            const mine = ++generation;

            connector(config).then(
                (opened) => {
                    if (stopped || mine !== generation) {
                        opened.close();

                        return;
                    }

                    connection = opened;
                    opened.onState((state) => {
                        if (mine !== generation) return;

                        if (state === 'error') {
                            // Before the first connection an error means the server is not there: stop at the first one.
                            if (!everConnected) giveUp();

                            return;
                        }

                        if (state === 'connected') {
                            socketUp = true;

                            if (everConnected && lost) {
                                lost = false;
                                options.onResync?.();
                            }

                            everConnected = true;
                        } else {
                            socketUp = false;
                            lost = everConnected;

                            if (!everConnected && (state === 'unavailable' || state === 'failed')) {
                                giveUp();

                                return;
                            }
                        }

                        update();
                    });
                    opened.subscribe(config.channel, INBOX_EVENT, {
                        event: (payload) => {
                            const signal = parseSignal(payload);

                            if (mine !== generation || signal === null) return;

                            pending.set(`${signal.source}:${signal.id}`, signal);

                            if (timer === null) {
                                timer = setTimeout(flush, debounce);
                            }
                        },
                        subscribed: () => {
                            if (mine !== generation) return;
                            subscribed = true;
                            update();
                        },
                        error: () => {
                            if (mine === generation) giveUp();
                        },
                    });
                },
                () => {
                    if (mine === generation) giveUp();
                },
            );
        },
        stop(): void {
            stopped = true;
            close();
        },
    };
}

/** {@see createInboxRealtime}, started when the page mounts and closed when it unmounts. */
export function useInboxRealtime(options: InboxRealtimeOptions): Pick<InboxRealtime, 'connected'> {
    const realtime = createInboxRealtime(options);

    onMounted(() => realtime.start());
    onBeforeUnmount(() => realtime.stop());

    return { connected: realtime.connected };
}

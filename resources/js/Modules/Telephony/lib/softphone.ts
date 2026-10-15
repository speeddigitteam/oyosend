import { UA, WebSocketInterface } from 'jssip';
import type { EndEvent, PeerConnectionEvent, RTCSession } from 'jssip/lib/RTCSession';
import type { IncomingRequest, OutgoingRequest } from 'jssip/lib/SIPMessage';
import type { RTCSessionEvent } from 'jssip/lib/UA';
import { onBeforeUnmount, ref, type Ref } from 'vue';
import { requestJson } from './http';

/**
 * The browser softphone (ADR 0036): JsSIP registers the member's own extension on the workspace's PBX over a secure
 * WebSocket and carries the audio with WebRTC. The member's credentials are fetched on request and live only in the
 * JsSIP agent; nothing is stored. The call log is kept in step in the background: a row when a call starts, then each
 * change; the PBX's own events later confirm it on the server.
 */

/** Whether the softphone can be used. */
export type PhoneState = 'connecting' | 'ready' | 'offline' | 'unconfigured';

/** The one call the softphone holds at a time. */
export type CallState = 'idle' | 'calling' | 'ringing' | 'incoming' | 'connected';

export type CallReport = 'ringing' | 'answered' | 'completed' | 'busy' | 'no_answer' | 'failed';

export interface SoftphoneConfig {
    wssUrl: string;
    sipUri: string;
    authorizationUser: string;
    password: string;
    iceServers: RTCIceServer[];
}

export interface SoftphoneRoutes {
    credentials: string;
    start: string;
    report: (callId: string) => string;
}

/** What a failed JsSIP session means for the log. An incoming call nobody answered is a missed call on the server. */
export function reportForCause(cause: string, outgoing: boolean): CallReport {
    if (!outgoing) return 'completed';

    switch (cause) {
        case 'Busy':
            return 'busy';
        case 'No Answer':
        case 'Request Timeout':
        case 'Unavailable':
        case 'Canceled':
            return 'no_answer';
        default:
            return 'failed';
    }
}

export function useSoftphone(routes: SoftphoneRoutes, audio: Ref<HTMLAudioElement | null>) {
    const phone = ref<PhoneState>('connecting');
    const call = ref<CallState>('idle');
    const remote = ref<string | null>(null);
    const connectedAt = ref<number | null>(null);
    const muted = ref(false);
    /** A reason worth telling the member: the microphone was refused, or the PBX would not register them. */
    const problem = ref<'microphone' | 'registration' | null>(null);

    let ua: UA | null = null;
    let session: RTCSession | null = null;
    let iceServers: RTCIceServer[] = [];
    let row: Promise<string | null> = Promise.resolve(null);

    const media = { mediaConstraints: { audio: true, video: false }, pcConfig: { iceServers } };

    async function connect(): Promise<void> {
        phone.value = 'connecting';
        const response = await requestJson<{ status: string; config?: SoftphoneConfig }>('GET', routes.credentials);
        const config = response.data?.config;

        if (!response.ok || !config) {
            phone.value = response.status === 409 ? 'unconfigured' : 'offline';

            return;
        }

        iceServers = config.iceServers;
        media.pcConfig.iceServers = iceServers;
        ua = new UA({
            sockets: [new WebSocketInterface(config.wssUrl)],
            uri: config.sipUri,
            authorization_user: config.authorizationUser,
            password: config.password,
            register: true,
            session_timers: false,
            user_agent: 'Oyosend',
        });
        ua.on('registered', () => {
            phone.value = 'ready';
            problem.value = null;
        });
        ua.on('unregistered', () => (phone.value = 'offline'));
        ua.on('registrationFailed', () => {
            phone.value = 'offline';
            problem.value = 'registration';
        });
        ua.on('disconnected', () => (phone.value = 'offline'));
        ua.on('newRTCSession', (event: RTCSessionEvent) => adopt(event));
        ua.start();
    }

    function bindAudio(connection: RTCPeerConnection): void {
        connection.addEventListener('track', (event: RTCTrackEvent) => {
            if (audio.value && event.streams[0]) audio.value.srcObject = event.streams[0];
        });
    }

    function adopt(event: RTCSessionEvent): void {
        const outgoing = String(event.originator) === 'local';

        // One call at a time: a second incoming call is told the member is busy.
        if (session !== null) {
            if (!outgoing) end(event.session, { status_code: 486 });

            return;
        }

        const current = event.session;
        session = current;
        muted.value = false;
        connectedAt.value = null;
        call.value = outgoing ? 'calling' : 'incoming';

        if (!outgoing) remote.value = current.remote_identity.uri.user;

        const sipCallId = outgoing ? (event.request as OutgoingRequest).call_id : (event.request as IncomingRequest).getHeader('Call-ID');
        row = startRow(remote.value ?? '', outgoing ? 'outbound' : 'inbound', sipCallId);

        current.on('peerconnection', (data: PeerConnectionEvent) => bindAudio(data.peerconnection));
        current.on('progress', () => {
            if (outgoing) {
                call.value = 'ringing';
                void report('ringing');
            }
        });
        current.on('confirmed', () => {
            call.value = 'connected';
            connectedAt.value = Date.now();
            void report('answered');
        });
        current.on('ended', () => finish('completed'));
        current.on('failed', (data: EndEvent) => {
            if (data.cause === 'User Denied Media Access') problem.value = 'microphone';
            finish(reportForCause(data.cause, outgoing));
        });

        if (outgoing && current.connection) bindAudio(current.connection);
    }

    async function startRow(number: string, direction: 'outbound' | 'inbound', clientCallId: string): Promise<string | null> {
        if (number === '' || clientCallId === '') return null;

        const response = await requestJson<{ id: string }>('POST', routes.start, { number, direction, client_call_id: clientCallId });

        return response.ok && response.data ? response.data.id : null;
    }

    async function report(status: CallReport): Promise<void> {
        const id = await row;

        if (id !== null) await requestJson('POST', routes.report(id), { status });
    }

    function finish(status: CallReport): void {
        void report(status);
        session = null;
        call.value = 'idle';
        connectedAt.value = null;
        muted.value = false;

        if (audio.value) audio.value.srcObject = null;
    }

    /** Places a call from the softphone. The number is E.164; false when the phone cannot call now. */
    function dial(number: string): boolean {
        if (ua === null || phone.value !== 'ready' || session !== null) return false;

        remote.value = number;
        problem.value = null;
        ua.call(number, media);

        return true;
    }

    function answer(): void {
        if (session !== null && call.value === 'incoming') session.answer(media);
    }

    function decline(): void {
        if (session !== null && call.value === 'incoming') end(session, { status_code: 603 });
    }

    function hangUp(): void {
        if (session !== null) end(session);
    }

    /** Ends a session; one that already ended (the other side hung up a moment ago) is left alone. */
    function end(target: RTCSession, options?: { status_code: number }): void {
        try {
            target.terminate(options);
        } catch {
            // Already terminated.
        }
    }

    function toggleMute(): void {
        if (session === null || call.value !== 'connected') return;

        if (muted.value) session.unmute({ audio: true });
        else session.mute({ audio: true });

        muted.value = !muted.value;
    }

    function sendTone(tone: string): void {
        if (session !== null && call.value === 'connected') session.sendDTMF(tone);
    }

    onBeforeUnmount(() => {
        if (session !== null) end(session);
        ua?.stop();
        ua = null;
    });

    return { phone, call, remote, connectedAt, muted, problem, connect, dial, answer, decline, hangUp, toggleMute, sendTone };
}

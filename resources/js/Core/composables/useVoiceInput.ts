import { usePage } from '@inertiajs/vue3';
import { computed, getCurrentInstance, onBeforeUnmount, ref } from 'vue';
import { preferredMimeType, transcribeClip } from '@/Core/lib/voice';

export type VoiceStatus = 'idle' | 'recording' | 'transcribing';

/** What went wrong, as a key under `shell.voice` (`server` carries the server's own translated message). */
export type VoiceProblem = 'microphone' | 'unsupported' | 'tooLarge' | 'failed' | 'nothingHeard' | 'server';

/** Speech louder than this (RMS of the time-domain signal, 0..1) counts as talking. */
const SPEECH_LEVEL = 0.02;
/** After the member has spoken, this much quiet ends the clip. */
const SILENCE_MS = 1500;

/**
 * Push to talk (ADR 0038): `start()` records from the microphone until `stop()`, a pause after speech, or the
 * server's length limit; the clip is then transcribed and `onTranscript` receives the text. Nothing runs on its
 * own here: the caller decides what the text does, and the member sees it.
 *
 * `available` is false when the workspace does not offer voice to this member or the browser cannot record;
 * a caller shows no microphone then.
 */
export function useVoiceInput(onTranscript: (text: string) => void) {
    const page = usePage();
    const offer = computed(() => page.props.voice ?? null);
    const supported =
        typeof window !== 'undefined' && typeof window.MediaRecorder !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function';

    const available = computed(() => offer.value !== null && supported);
    const status = ref<VoiceStatus>('idle');
    const problem = ref<VoiceProblem | null>(null);
    const serverMessage = ref<string | null>(null);
    const secondsLeft = ref(0);

    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let audio: AudioContext | null = null;
    let chunks: Blob[] = [];
    let timer: ReturnType<typeof setInterval> | null = null;
    let levelFrame: number | null = null;
    let discard = false;
    let abort: AbortController | null = null;

    function release(): void {
        if (timer !== null) clearInterval(timer);
        if (levelFrame !== null && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(levelFrame);
        timer = null;
        levelFrame = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        void audio?.close().catch(() => undefined);
        audio = null;
    }

    /** Ends the clip once the member has spoken and then paused. Best effort: without Web Audio the timer ends it. */
    function watchForPause(source: MediaStream): void {
        const AudioContextClass = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

        if (AudioContextClass === undefined || typeof requestAnimationFrame !== 'function') return;

        try {
            audio = new AudioContextClass();
            const analyser = audio.createAnalyser();
            analyser.fftSize = 1024;
            audio.createMediaStreamSource(source).connect(analyser);
            const samples = new Float32Array(analyser.fftSize);
            let spoke = false;
            let quietSince = 0;

            const tick = (): void => {
                if (status.value !== 'recording') return;

                analyser.getFloatTimeDomainData(samples);
                const level = Math.sqrt(samples.reduce((sum, value) => sum + value * value, 0) / samples.length);
                const now = performance.now();

                if (level > SPEECH_LEVEL) {
                    spoke = true;
                    quietSince = now;
                } else if (spoke && now - quietSince > SILENCE_MS) {
                    stop();

                    return;
                }

                levelFrame = requestAnimationFrame(tick);
            };

            levelFrame = requestAnimationFrame(tick);
        } catch {
            audio = null;
        }
    }

    async function start(): Promise<void> {
        if (status.value !== 'idle' || offer.value === null) return;

        problem.value = null;
        serverMessage.value = null;

        if (!supported) {
            problem.value = 'unsupported';

            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            problem.value = 'microphone';

            return;
        }

        const mimeType = preferredMimeType((type) => MediaRecorder.isTypeSupported(type));
        recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        chunks = [];
        discard = false;

        recorder.addEventListener('dataavailable', (event: BlobEvent) => {
            if (event.data.size > 0) chunks.push(event.data);
        });
        recorder.addEventListener('stop', () => void finish(mimeType ?? recorder?.mimeType ?? 'audio/webm'));

        const limit = offer.value.maxSeconds;
        secondsLeft.value = limit;
        status.value = 'recording';
        recorder.start(250);

        timer = setInterval(() => {
            secondsLeft.value -= 1;

            if (secondsLeft.value <= 0) stop();
        }, 1000);

        watchForPause(stream);
    }

    function stop(): void {
        if (status.value !== 'recording' || recorder === null) return;

        status.value = 'transcribing';

        // The recorder first, so its last chunk is flushed before the microphone is released.
        if (recorder.state !== 'inactive') recorder.stop();

        release();
    }

    /** Drop the recording, or the answer still on its way. */
    function cancel(): void {
        discard = true;
        abort?.abort();

        if (status.value === 'recording' && recorder !== null && recorder.state !== 'inactive') recorder.stop();

        release();
        status.value = 'idle';
    }

    async function finish(mimeType: string): Promise<void> {
        const clip = new Blob(chunks, { type: mimeType });
        chunks = [];
        recorder = null;

        if (discard) return;

        if (offer.value !== null && clip.size > offer.value.maxBytes) {
            problem.value = 'tooLarge';
            status.value = 'idle';

            return;
        }

        abort = new AbortController();

        try {
            const result = await transcribeClip(clip, page.props.app.locale, abort.signal);

            if (discard) return;

            if (!result.ok) {
                problem.value = result.message !== null ? 'server' : 'failed';
                serverMessage.value = result.message;
            } else if (result.text.trim() === '') {
                problem.value = 'nothingHeard';
            } else {
                onTranscript(result.text.trim());
            }
        } catch {
            // Cancelled while the server was working.
        } finally {
            abort = null;
            status.value = 'idle';
        }
    }

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(cancel);
    }

    return { available, status, problem, serverMessage, secondsLeft, start, stop, cancel };
}

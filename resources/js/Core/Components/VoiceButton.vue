<script setup lang="ts">
import { Mic, Square, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import { useVoiceInput } from '@/Core/composables/useVoiceInput';

/**
 * Push-to-talk microphone (ADR 0038). Renders nothing unless the workspace offers voice to this member and
 * the browser can record. Emits the transcript; the parent puts it where the member can see and correct it.
 */
withDefaults(
    defineProps<{
        size?: 'sm' | 'md';
        /** What speaking here does, as the microphone's tooltip. */
        hint?: string;
    }>(),
    { size: 'md', hint: undefined },
);

const emit = defineEmits<{ transcript: [text: string] }>();

const { t } = useI18n();
const voice = useVoiceInput((text) => emit('transcript', text));

const message = computed<string | null>(() => {
    if (voice.status.value === 'recording') return t('shell.voice.listening', { seconds: voice.secondsLeft.value });
    if (voice.status.value === 'transcribing') return t('shell.voice.transcribing');
    if (voice.problem.value === 'server') return voice.serverMessage.value;
    if (voice.problem.value === 'nothingHeard') return t('shell.voice.nothingHeard');
    if (voice.problem.value !== null) return t(`shell.voice.errors.${voice.problem.value}`);

    return null;
});

defineExpose({ start: voice.start, stop: voice.stop, cancel: voice.cancel, status: voice.status });
</script>

<template>
    <div v-if="voice.available.value" class="flex min-w-0 items-center gap-2" data-testid="voice">
        <Button
            v-if="voice.status.value === 'idle'"
            icon
            :size="size"
            variant="ghost"
            :aria-label="t('shell.voice.start')"
            :title="hint ?? t('shell.voice.start')"
            data-testid="voice-start"
            @click="voice.start()"
        >
            <Mic class="size-4" aria-hidden="true" />
        </Button>
        <template v-else-if="voice.status.value === 'recording'">
            <Button
                icon
                :size="size"
                variant="primary"
                :aria-label="t('shell.voice.stop')"
                :title="t('shell.voice.stop')"
                aria-pressed="true"
                data-testid="voice-stop"
                @click="voice.stop()"
            >
                <Square class="size-3.5" aria-hidden="true" />
            </Button>
            <Button icon :size="size" variant="ghost" :aria-label="t('shell.voice.cancel')" :title="t('shell.voice.cancel')" @click="voice.cancel()">
                <X class="size-4" aria-hidden="true" />
            </Button>
        </template>
        <template v-else>
            <span class="flex size-8 items-center justify-center" aria-hidden="true"><Spinner /></span>
            <Button icon :size="size" variant="ghost" :aria-label="t('shell.voice.cancel')" :title="t('shell.voice.cancel')" @click="voice.cancel()">
                <X class="size-4" aria-hidden="true" />
            </Button>
        </template>
        <!-- Read out as it changes: listening, transcribing, or why nothing came of it. -->
        <p
            aria-live="polite"
            :class="['min-w-0 truncate text-[0.8125rem]', voice.problem.value !== null && voice.status.value === 'idle' ? 'text-danger' : 'text-ink-subtle']"
            data-testid="voice-status"
        >
            {{ message }}
        </p>
    </div>
</template>

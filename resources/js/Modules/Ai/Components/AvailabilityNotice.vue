<script setup lang="ts">
import { Info, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Availability } from './types';

const props = defineProps<{ availability: Availability }>();

const { t, te } = useI18n();

/** Translated title and body; an unknown state falls back to the message the server sent. */
const title = computed(() => (te(`ai.state.${props.availability.state}.title`) ? t(`ai.state.${props.availability.state}.title`) : props.availability.message));
const body = computed(() => (te(`ai.state.${props.availability.state}.body`) ? t(`ai.state.${props.availability.state}.body`) : ''));
const alarming = computed(() => ['limit_reached', 'unavailable'].includes(props.availability.state));
</script>

<template>
    <div
        v-if="availability.state !== 'ready'"
        :role="alarming ? 'alert' : 'status'"
        :data-state="availability.state"
        data-testid="ai-availability"
        :class="['mb-4 flex items-start gap-3 rounded-card px-4 py-3', alarming ? 'bg-danger-soft text-danger' : 'bg-info-soft text-info']"
    >
        <TriangleAlert v-if="alarming" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <Info v-else class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ title }}</p>
            <p v-if="body" class="mt-0.5 text-[0.8125rem]">{{ body }}</p>
        </div>
    </div>
</template>

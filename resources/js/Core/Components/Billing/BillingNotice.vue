<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock, Info } from 'lucide-vue-next';
import type { Component } from 'vue';

type Tone = 'ok' | 'info' | 'warn' | 'danger';

/**
 * Something about billing the person should read before the rest of the
 * page: an invoice to pay, what became of a payment. A panel of its own, with
 * the body in the default slot and buttons in `#actions`.
 */
withDefaults(
    defineProps<{
        title: string;
        tone?: Tone;
    }>(),
    { tone: 'info' },
);

const TONES: Record<Tone, { chip: string; icon: Component }> = {
    ok: { chip: 'bg-ok-soft text-ok', icon: CircleCheck },
    info: { chip: 'bg-info-soft text-info', icon: Clock },
    warn: { chip: 'bg-warn-soft text-warn', icon: Info },
    danger: { chip: 'bg-danger-soft text-danger', icon: CircleAlert },
};
</script>

<template>
    <section class="glass flex flex-wrap items-start gap-x-4 gap-y-3 rounded-card px-5 py-4">
        <span :class="['flex size-9 shrink-0 items-center justify-center rounded-full', TONES[tone].chip]" aria-hidden="true">
            <component :is="TONES[tone].icon" class="size-4.5" />
        </span>

        <div class="min-w-0 flex-1 basis-56">
            <h2 class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ title }}</h2>
            <div class="mt-0.5 flex flex-col gap-1 text-ink-muted">
                <slot />
            </div>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </section>
</template>

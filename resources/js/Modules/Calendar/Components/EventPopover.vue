<script setup lang="ts">
import { AlignLeft, Bell, CalendarDays, Check, CircleHelp, Lock, MapPin, Pencil, Repeat, Trash2, Users, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { describeReminder, describeWhen } from '../lib/describe';
import { colorVar, useCalendarFormat } from '../lib/format';
import { describeRule } from '../lib/recurrence';
import type { CalendarEvent, CalendarInfo, GuestResponse } from '../types';

/**
 * The card an event opens: when and where, how it repeats, guests and their answers, notes, calendar and
 * notifications, with edit and delete for those who may, and "Going?" for a guest. It sits beside the event on a
 * wide screen and as a sheet at the bottom on a narrow one. Escape or a click outside closes it and focus returns
 * to the event.
 */
const props = defineProps<{
    event: CalendarEvent;
    calendar: CalendarInfo | undefined;
    anchor: HTMLElement | null;
}>();

const emit = defineEmits<{
    close: [];
    edit: [];
    delete: [];
    respond: [response: Exclude<GuestResponse, 'needs_action'>];
}>();

const { t } = useI18n();
const format = useCalendarFormat();

const card = ref<HTMLElement | null>(null);
const position = ref<Record<string, string>>({});
const titleId = `calendar-event-${Math.random().toString(36).slice(2)}`;

const title = computed(() => (props.event.busy ? t('calendar.grid.busy') : props.event.title || t('calendar.grid.untitled')));
const when = computed(() => describeWhen(props.event, t, format.value));
const repeats = computed(() => (props.event.recurrence ? describeRule(props.event.recurrence, props.event.start.slice(0, 10), t, format.value) : null));
const guests = computed(() => props.event.guests ?? []);
const answered = computed(() => ({
    accepted: guests.value.filter((guest) => guest.response === 'accepted').length,
    declined: guests.value.filter((guest) => guest.response === 'declined').length,
    tentative: guests.value.filter((guest) => guest.response === 'tentative').length,
    waiting: guests.value.filter((guest) => guest.response === 'needs_action').length,
}));
const guestSummary = computed(() =>
    [
        answered.value.accepted ? t('calendar.event.answers.accepted', { count: format.value.number(answered.value.accepted) }) : null,
        answered.value.tentative ? t('calendar.event.answers.tentative', { count: format.value.number(answered.value.tentative) }) : null,
        answered.value.declined ? t('calendar.event.answers.declined', { count: format.value.number(answered.value.declined) }) : null,
        answered.value.waiting ? t('calendar.event.answers.waiting', { count: format.value.number(answered.value.waiting) }) : null,
    ].filter(Boolean).join(', '),
);

function place(): void {
    if (window.innerWidth < 640 || !card.value) {
        position.value = {};

        return;
    }

    const width = card.value.offsetWidth;
    const height = card.value.offsetHeight;

    // Opened from a list rather than from the event itself: the middle of the screen.
    if (!props.anchor || !props.anchor.isConnected) {
        position.value = { left: `${Math.max(12, (window.innerWidth - width) / 2)}px`, top: `${Math.max(12, (window.innerHeight - height) / 3)}px` };

        return;
    }

    const rect = props.anchor.getBoundingClientRect();
    const gap = 8;
    const left = rect.right + gap + width <= window.innerWidth - 12 ? rect.right + gap : Math.max(12, rect.left - gap - width);
    const top = Math.min(Math.max(12, rect.top), window.innerHeight - height - 12);

    position.value = { left: `${left}px`, top: `${Math.max(12, top)}px` };
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        emit('close');
    }
}

onMounted(async () => {
    await nextTick();
    place();
    card.value?.focus();
    window.addEventListener('resize', place);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', place);
    props.anchor?.focus?.();
});

const responses = ['accepted', 'declined', 'tentative'] as const;
const responseIcon = { accepted: Check, declined: X, tentative: CircleHelp, needs_action: null } as const;
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[60]" @keydown="onKey">
            <div class="absolute inset-0 bg-[rgb(8_14_12/0.3)] sm:bg-transparent" aria-hidden="true" @pointerdown="emit('close')" />
            <section
                ref="card"
                role="dialog"
                aria-modal="false"
                :aria-labelledby="titleId"
                tabindex="-1"
                :style="position"
                class="glass-solid absolute inset-x-2 bottom-2 max-h-[80dvh] animate-pop overflow-y-auto rounded-panel p-4 shadow-float outline-none sm:inset-x-auto sm:bottom-auto sm:w-[26rem] sm:max-h-[min(36rem,calc(100dvh-1.5rem))]"
            >
                <div class="-mt-1 -mr-1 mb-2 flex items-center justify-end gap-0.5">
                    <button v-if="event.editable" type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.event.edit')" :title="t('calendar.event.edit')" @click="emit('edit')">
                        <Pencil class="size-4" aria-hidden="true" />
                    </button>
                    <button v-if="event.editable" type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-danger focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.event.delete')" :title="t('calendar.event.delete')" @click="emit('delete')">
                        <Trash2 class="size-4" aria-hidden="true" />
                    </button>
                    <button type="button" class="rounded-full p-2 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.event.close')" @click="emit('close')">
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </div>

                <div class="flex gap-3" :style="colorVar(event.color)">
                    <span class="mt-1.5 size-3.5 shrink-0 rounded-[0.25rem] bg-(--ev)" aria-hidden="true" />
                    <div class="min-w-0">
                        <h2 :id="titleId" :class="['font-display text-xl leading-snug break-words text-ink', event.myResponse === 'declined' ? 'line-through' : '']">{{ title }}</h2>
                        <p class="mt-0.5 text-sm text-ink-muted">{{ when }}</p>
                        <p v-if="repeats" class="mt-0.5 flex items-center gap-1 text-sm text-ink-muted">
                            <Repeat class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ repeats }}
                        </p>
                    </div>
                </div>

                <p v-if="event.busy" class="mt-4 flex items-center gap-3 text-sm text-ink-muted">
                    <Lock class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    {{ t('calendar.event.busyNote') }}
                </p>

                <dl v-else class="mt-4 space-y-3 text-sm">
                    <div v-if="event.location" class="flex gap-3">
                        <dt class="shrink-0"><MapPin class="size-4 text-ink-subtle" aria-hidden="true" /><span class="sr-only">{{ t('calendar.editor.location') }}</span></dt>
                        <dd class="min-w-0 break-words text-ink">{{ event.location }}</dd>
                    </div>

                    <div v-if="guests.length" class="flex gap-3">
                        <dt class="shrink-0"><Users class="size-4 text-ink-subtle" aria-hidden="true" /><span class="sr-only">{{ t('calendar.editor.guests') }}</span></dt>
                        <dd class="min-w-0 flex-1">
                            <p class="text-ink">{{ t(`calendar.event.guestCount.${guests.length === 1 ? 'one' : 'other'}`, { count: format.number(guests.length) }) }}</p>
                            <p v-if="guestSummary" class="text-xs text-ink-subtle">{{ guestSummary }}</p>
                            <ul class="mt-1.5 space-y-1">
                                <li v-if="event.organizer" class="flex items-center gap-2 text-ink">
                                    <span class="truncate">{{ event.organizer.name }}</span>
                                    <span class="text-xs text-ink-subtle">{{ t('calendar.event.organizer') }}</span>
                                </li>
                                <li v-for="guest in guests" :key="guest.id" class="flex items-center gap-2 text-ink">
                                    <component :is="responseIcon[guest.response]" v-if="responseIcon[guest.response]" :class="['size-3.5 shrink-0', guest.response === 'accepted' ? 'text-ok' : guest.response === 'declined' ? 'text-danger' : 'text-ink-subtle']" aria-hidden="true" />
                                    <span v-else class="size-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ guest.name }}</span>
                                    <span class="sr-only">{{ t(`calendar.event.responses.${guest.response}`) }}</span>
                                </li>
                            </ul>
                        </dd>
                    </div>

                    <div v-if="event.description" class="flex gap-3">
                        <dt class="shrink-0"><AlignLeft class="size-4 text-ink-subtle" aria-hidden="true" /><span class="sr-only">{{ t('calendar.editor.description') }}</span></dt>
                        <dd class="min-w-0 break-words whitespace-pre-line text-ink">{{ event.description }}</dd>
                    </div>

                    <div v-if="event.reminders?.length" class="flex gap-3">
                        <dt class="shrink-0"><Bell class="size-4 text-ink-subtle" aria-hidden="true" /><span class="sr-only">{{ t('calendar.editor.reminders') }}</span></dt>
                        <dd class="min-w-0 text-ink">
                            <p v-for="minutes in event.reminders" :key="minutes">{{ describeReminder(minutes, event.allDay, t, format) }}</p>
                        </dd>
                    </div>

                    <div v-if="calendar" class="flex gap-3">
                        <dt class="shrink-0"><CalendarDays class="size-4 text-ink-subtle" aria-hidden="true" /><span class="sr-only">{{ t('calendar.editor.calendar') }}</span></dt>
                        <dd class="min-w-0 text-ink">
                            {{ calendar.name }}
                            <span v-if="event.visibility === 'private'" class="ml-1 inline-flex items-center gap-1 text-xs text-ink-subtle"><Lock class="size-3" aria-hidden="true" />{{ t('calendar.editor.visibilityPrivate') }}</span>
                            <span v-if="event.showAs === 'free'" class="ml-1 text-xs text-ink-subtle">{{ t('calendar.editor.free') }}</span>
                        </dd>
                    </div>
                </dl>

                <div v-if="event.myResponse" class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                    <span class="mr-auto text-sm font-medium text-ink">{{ t('calendar.event.going') }}</span>
                    <div class="flex gap-1" role="group" :aria-label="t('calendar.event.going')">
                        <button
                            v-for="response in responses"
                            :key="response"
                            type="button"
                            :aria-pressed="event.myResponse === response"
                            :class="[
                                'rounded-full border px-3 py-1 text-sm transition focus-visible:ring-2 focus-visible:ring-ring',
                                event.myResponse === response ? 'border-brand bg-brand-soft font-medium text-brand-text' : 'border-line-strong text-ink-muted hover:text-ink',
                            ]"
                            @click="emit('respond', response)"
                        >
                            {{ t(`calendar.event.answer.${response}`) }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>

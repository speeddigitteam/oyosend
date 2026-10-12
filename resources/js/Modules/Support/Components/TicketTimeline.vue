<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import type { TicketEventRow } from './types';

defineProps<{ events: TicketEventRow[] }>();

const { t, te } = useI18n();
const format = useFormat();

function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

function enumLabel(group: string, value: unknown): string {
    const key = `support.${group}.${text(value)}`;

    return te(key) ? t(key) : text(value);
}

function fieldNames(value: unknown): string {
    return text(value)
        .split(',')
        .filter((name) => name !== '')
        .map((name) => (te(`support.fields.${name}`) ? t(`support.fields.${name}`) : name))
        .join(', ');
}

/** One sentence for a history entry. Unknown kinds fall back to the kind itself. */
function describe(event: TicketEventRow): string {
    const data = event.data;
    const key = `support.event.${event.kind}`;

    if (!te(key)) {
        return event.kind;
    }

    switch (event.kind) {
        case 'created':
            return t(key, { channel: enumLabel('channel', data.channel), priority: enumLabel('priority', data.priority) });
        case 'updated':
            return t(key, { fields: fieldNames(data.fields) });
        case 'assigned': {
            const name = text(data.toName);
            const auto = data.auto === true;

            if (name !== '') {
                return t(auto ? 'support.event.assignedAutoTo' : 'support.event.assignedTo', { name });
            }

            return t(auto ? 'support.event.assignedAuto' : key);
        }
        case 'unassigned':
            return text(data.fromName) !== '' ? t('support.event.unassignedFrom', { name: text(data.fromName) }) : t(key);
        case 'queue_changed':
            if (text(data.toName) !== '') {
                return text(data.fromName) !== ''
                    ? t('support.event.queueChangedFromTo', { from: text(data.fromName), to: text(data.toName) })
                    : t('support.event.queueChangedTo', { to: text(data.toName) });
            }

            return t(key);
        case 'status_changed':
            return t(key, { from: enumLabel('status', data.from), to: enumLabel('status', data.to) });
        case 'priority_changed':
            return t(key, { from: enumLabel('priority', data.from), to: enumLabel('priority', data.to) });
        case 'merged_into':
            return t(key, { number: text(data.target_number) });
        case 'merged_from':
            return t(key, { number: text(data.source_number), count: format.number(Number(data.messages ?? 0)) });
        case 'split_to':
        case 'split_from':
            return t(key, { number: text(data.number), count: format.number(Number(data.messages ?? 0)) });
        case 'sla_breached':
            return t(key, { target: te(`support.sla.target.${text(data.target)}`) ? t(`support.sla.target.${text(data.target)}`) : text(data.target) });
        case 'survey_answered':
            return t(key, { rating: format.number(Number(data.rating ?? 0)) });
        default:
            return t(key);
    }
}
</script>

<template>
    <ol class="grid gap-0" :aria-label="t('support.timeline.label')">
        <li v-for="event in events" :key="event.id" class="relative border-l border-line-strong pb-4 ps-4 last:pb-0">
            <span class="absolute -start-[0.3125rem] top-1.5 size-2.5 rounded-full border border-line-strong bg-canvas" aria-hidden="true" />
            <p class="text-sm text-ink wrap-anywhere">{{ describe(event) }}</p>
            <p class="tabular text-xs text-ink-subtle">
                <span>{{ event.actor ?? t('support.timeline.system') }}</span>
                <span aria-hidden="true"> · </span>
                <time :datetime="event.createdAt">{{ format.date(event.createdAt, 'datetime') }}</time>
            </p>
        </li>
    </ol>
</template>

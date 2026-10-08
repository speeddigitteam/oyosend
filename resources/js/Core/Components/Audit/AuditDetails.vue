<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { permissionLabel } from '@/Core/lib/labels';
import { isBlank, type DetailRow } from './properties';

/**
 * The particulars of one audit entry: "field: before → after" for changes,
 * "field: value" for everything else. Build the rows with `detailRows()`.
 *
 * Give it a width from outside; field names sit beside their values when
 * there is room and above them when there is not.
 */
const props = defineProps<{
    rows: DetailRow[];
    /** Event key of the entry, e.g. `member.role_changed`. */
    event: string;
    /** Says which entry this belongs to, for people who cannot see where it sits. */
    label: string;
}>();

const { t, te } = useI18n();
const format = useFormat();

type Tone = 'plain' | 'muted' | 'mono';

interface Shown {
    text: string;
    tone: Tone;
}

/** Events that record a change without naming the field: what the change is to. */
const UNNAMED_FIELD: Record<string, string> = {
    'member.role_changed': 'role',
};

const ISO_WITH_ZONE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/;
// How the database prints a timestamp. The application keeps time in UTC.
const DATABASE_DATETIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/i;
// A dot in a key would be read as nesting by vue-i18n.
const PLAIN_KEY = /^\w+$/;

function fieldLabel(field: string | null, kind: DetailRow['kind']): string {
    if (field === null) {
        return kind === 'change' ? t('audit.details.change') : t('audit.details.value');
    }

    if (PLAIN_KEY.test(field) && te(`audit.fields.${field}`)) {
        return t(`audit.fields.${field}`);
    }

    const words = field.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function scalar(value: unknown, field: string | null): Shown {
    if (isBlank(value)) {
        return { text: t('audit.details.none'), tone: 'muted' };
    }

    // What the server writes in place of passwords, tokens and secrets.
    if (value === '[redacted]') {
        return { text: t('audit.details.redacted'), tone: 'muted' };
    }

    if (typeof value === 'boolean') {
        return { text: value ? t('audit.details.yes') : t('audit.details.no'), tone: 'plain' };
    }

    if (typeof value === 'number') {
        // Flags come out of the database as 0 and 1.
        if (field !== null && /^(is|has)_/.test(field) && (value === 0 || value === 1)) {
            return { text: value === 1 ? t('audit.details.yes') : t('audit.details.no'), tone: 'plain' };
        }

        // Ungrouped: the number may just as well be an identifier or a year.
        return { text: format.number(value, { useGrouping: false }), tone: 'plain' };
    }

    if (typeof value !== 'string') {
        // Nested data the trail has no better shape for: show it as recorded.
        return { text: JSON.stringify(value), tone: 'mono' };
    }

    if (field === 'status' && PLAIN_KEY.test(value) && te(`common.status.${value}`)) {
        return { text: t(`common.status.${value}`), tone: 'plain' };
    }

    if (field === 'added' || field === 'removed') {
        // Permission names; anything else comes back unchanged.
        return { text: permissionLabel(value), tone: 'plain' };
    }

    if (ISO_WITH_ZONE.test(value)) {
        return { text: format.date(value, 'datetime') || value, tone: 'plain' };
    }

    if (DATABASE_DATETIME.test(value)) {
        return { text: format.date(`${value.replace(' ', 'T')}Z`, 'datetime') || value, tone: 'plain' };
    }

    return { text: value, tone: ULID.test(value) ? 'mono' : 'plain' };
}

function shown(value: unknown, field: string | null): Shown {
    if (Array.isArray(value) && value.length > 0 && value.every((item) => item === null || typeof item !== 'object')) {
        return { text: value.map((item) => scalar(item, field).text).join(', '), tone: 'plain' };
    }

    return scalar(value, field);
}

const display = computed(() =>
    props.rows.map((row) => {
        const field = row.field ?? UNNAMED_FIELD[props.event] ?? null;
        const label = fieldLabel(field, row.kind);

        return row.kind === 'change'
            ? { kind: row.kind, label, before: shown(row.before, field), after: shown(row.after, field) }
            : { kind: row.kind, label, value: shown(row.value, field) };
    }),
);

/**
 * In a change the old value is set back a step and the new one brought
 * forward; placeholders ("none") are quieter than either.
 */
function classes(value: Shown, role: 'before' | 'after' | 'value'): string[] {
    return [
        'min-w-0',
        value.tone === 'muted' ? 'text-ink-subtle' : role === 'before' ? 'text-ink-muted' : 'text-ink',
        value.tone === 'mono' ? 'font-mono text-xs break-all' : '',
        role === 'after' && value.tone === 'plain' ? 'font-medium' : '',
    ];
}
</script>

<template>
    <div class="@container text-left whitespace-normal">
        <p class="sr-only">{{ label }}</p>

        <!-- Each pair is a row of the list's own grid (subgrid), so the values line up under one another. -->
        <dl class="glass-well grid grid-cols-1 gap-x-6 gap-y-2.5 rounded-control px-4 py-3 text-[0.8125rem] @sm:grid-cols-[auto_minmax(0,1fr)] @sm:gap-y-1.5">
            <div v-for="(row, index) in display" :key="index" class="col-span-full grid grid-cols-subgrid gap-y-0.5 @sm:items-baseline">
                <dt class="eyebrow">{{ row.label }}</dt>

                <dd v-if="row.kind === 'change'" class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5 wrap-break-word">
                    <span :class="classes(row.before, 'before')">{{ row.before.text }}</span>
                    <ArrowRight class="size-3 shrink-0 self-center text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('audit.details.becomes') }}</span>
                    <span :class="classes(row.after, 'after')">{{ row.after.text }}</span>
                </dd>
                <dd v-else :class="['wrap-break-word', ...classes(row.value, 'value')]">{{ row.value.text }}</dd>
            </div>
        </dl>
    </div>
</template>

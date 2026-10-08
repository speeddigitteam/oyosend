<script setup lang="ts" generic="T extends object">
import { ArrowDown, ArrowUp, ChevronsUpDown, SlidersHorizontal } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from './Button.vue';
import Checkbox from './Checkbox.vue';
import Dropdown from './Dropdown.vue';
import Skeleton from './Skeleton.vue';

export interface Column {
    /** Property of the row, and the name of the `#cell-{key}` slot. */
    key: string;
    label: string;
    align?: 'left' | 'right' | 'center';
    /** Utility classes for the column, e.g. a width. */
    class?: string;
    sortable?: boolean;
    /** Can be switched off from the Columns menu. */
    hideable?: boolean;
    hiddenByDefault?: boolean;
    /** Header is announced to screen readers but not shown (action columns). */
    srOnly?: boolean;
}

export interface SortState {
    key: string;
    direction: 'asc' | 'desc';
}

/**
 * Data table with sorting and column visibility.
 *
 *  - Render a cell with `<template #cell-{key}="{ row, value }">`.
 *  - Sorting is local by default. For server-side sorting pass
 *    `sort-mode="server"` and react to `update:sort`.
 *  - Pagination is separate (see Pagination) because it belongs to the
 *    request, not to the table.
 */
const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        /** Property that uniquely identifies a row. */
        rowKey: keyof T & string;
        /** Announced to screen readers as the table's caption. */
        caption: string;
        loading?: boolean;
        sortMode?: 'client' | 'server';
        /** Persists the chosen columns for this table on this device. */
        storageKey?: string;
    }>(),
    { sortMode: 'client', storageKey: undefined },
);

const sort = defineModel<SortState | null>('sort', { default: null });

const { t } = useI18n();

function initiallyHidden(): string[] {
    const defaults = props.columns.filter((column) => column.hiddenByDefault).map((column) => column.key);

    if (!props.storageKey) {
        return defaults;
    }

    try {
        const stored = window.localStorage.getItem(`oyo:columns:${props.storageKey}`);

        return stored ? (JSON.parse(stored) as string[]) : defaults;
    } catch {
        return defaults;
    }
}

const hidden = ref<string[]>(initiallyHidden());

watch(hidden, (value) => {
    if (!props.storageKey) {
        return;
    }

    try {
        window.localStorage.setItem(`oyo:columns:${props.storageKey}`, JSON.stringify(value));
    } catch {
        // Not persisted; the choice still applies to this page view.
    }
});

const hideable = computed(() => props.columns.filter((column) => column.hideable));
const visible = computed(() => props.columns.filter((column) => !column.hideable || !hidden.value.includes(column.key)));

function setVisible(key: string, shown: boolean): void {
    hidden.value = shown ? hidden.value.filter((item) => item !== key) : [...hidden.value, key];
}

function toggleSort(column: Column): void {
    if (!column.sortable) {
        return;
    }

    if (sort.value?.key !== column.key) {
        sort.value = { key: column.key, direction: 'asc' };
    } else if (sort.value.direction === 'asc') {
        sort.value = { key: column.key, direction: 'desc' };
    } else {
        sort.value = null;
    }
}

function compare(a: unknown, b: unknown): number {
    if (a === null || a === undefined) {
        return b === null || b === undefined ? 0 : 1;
    }

    if (b === null || b === undefined) {
        return -1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

const sorted = computed<T[]>(() => {
    const state = sort.value;

    if (props.sortMode === 'server' || !state) {
        return props.rows;
    }

    const factor = state.direction === 'asc' ? 1 : -1;

    return [...props.rows].sort(
        (a, b) => compare((a as Record<string, unknown>)[state.key], (b as Record<string, unknown>)[state.key]) * factor,
    );
});

const ALIGN = { left: 'text-left', right: 'text-right', center: 'text-center' } as const;

function ariaSort(column: Column): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
        return undefined;
    }

    if (sort.value?.key !== column.key) {
        return 'none';
    }

    return sort.value.direction === 'asc' ? 'ascending' : 'descending';
}
</script>

<template>
    <div>
        <div v-if="$slots.toolbar || hideable.length" class="flex flex-wrap items-center justify-between gap-3 px-5 pb-3">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <slot name="toolbar" />
            </div>

            <Dropdown v-if="hideable.length" width="w-52">
                <template #trigger>
                    <Button size="sm" variant="ghost">
                        <template #icon><SlidersHorizontal class="size-3.5" aria-hidden="true" /></template>
                        {{ t('common.actions.columns') }}
                    </Button>
                </template>
                <div class="flex flex-col gap-2.5 p-2">
                    <Checkbox
                        v-for="column in hideable"
                        :key="column.key"
                        :model-value="!hidden.includes(column.key)"
                        :label="column.label"
                        @update:model-value="setVisible(column.key, $event)"
                    />
                </div>
            </Dropdown>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-0 text-left">
                <caption class="sr-only">
                    {{ caption }}
                </caption>
                <thead>
                    <tr>
                        <th
                            v-for="column in visible"
                            :key="column.key"
                            scope="col"
                            :aria-sort="ariaSort(column)"
                            :class="[
                                'eyebrow border-y border-line bg-[var(--hover)] px-5 py-2.5 font-medium whitespace-nowrap first:pl-5 last:pr-5',
                                ALIGN[column.align ?? 'left'],
                                column.class,
                            ]"
                        >
                            <span v-if="column.srOnly" class="sr-only">{{ column.label }}</span>
                            <button
                                v-else-if="column.sortable"
                                type="button"
                                class="-mx-1.5 inline-flex items-center gap-1 rounded-chip px-1.5 py-0.5 tracking-[inherit] uppercase transition-colors hover:text-ink"
                                :title="
                                    sort?.key === column.key && sort.direction === 'asc'
                                        ? t('common.table.sortDescending')
                                        : t('common.table.sortAscending')
                                "
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <ArrowUp v-if="sort?.key === column.key && sort.direction === 'asc'" class="size-3 text-brand-text" aria-hidden="true" />
                                <ArrowDown v-else-if="sort?.key === column.key" class="size-3 text-brand-text" aria-hidden="true" />
                                <ChevronsUpDown v-else class="size-3 opacity-50" aria-hidden="true" />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>

                <tbody v-if="loading" :aria-label="t('common.table.loading')">
                    <tr v-for="index in 5" :key="index">
                        <td v-for="column in visible" :key="column.key" class="border-b border-line px-5 py-3.5">
                            <Skeleton class="h-4" :style="{ width: `${45 + ((index * 17 + column.key.length * 11) % 45)}%` }" />
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <tr v-for="row in sorted" :key="String(row[rowKey])" class="group transition-colors hover:bg-[var(--hover)]">
                        <td
                            v-for="column in visible"
                            :key="column.key"
                            :class="[
                                'border-b border-line px-5 py-3 align-middle group-last:border-b-0',
                                ALIGN[column.align ?? 'left'],
                                column.class,
                            ]"
                        >
                            <slot :name="`cell-${column.key}`" :row="row" :value="(row as Record<string, unknown>)[column.key]">
                                {{ (row as Record<string, unknown>)[column.key] ?? '—' }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <slot v-if="!loading && rows.length === 0" name="empty" />
    </div>
</template>

<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, FilterX, ScrollText, Search, SearchX } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AuditDetails from '@/Core/Components/Audit/AuditDetails.vue';
import { detailRows, type AuditEntry } from '@/Core/Components/Audit/properties';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { eventFamilyLabel, eventLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import type { CursorPaginated } from '@/Core/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** Newest first, 30 to a page. */
    entries: CursorPaginated<AuditEntry>;
    /** The filters as applied; an empty string means "not set". */
    filters: { search: string; event: string; from: string; to: string };
    /** Event families present in this workspace's trail (`auth`, `member`, `role` ...). */
    eventGroups: string[];
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

/*
 * Filters are applied on request rather than as you type: a half-entered
 * date range is not worth a round trip, and the server checks the range.
 */
const form = useForm({
    search: props.filters.search,
    event: props.filters.event,
    from: props.filters.from,
    to: props.filters.to,
});

const applied = computed(() => Object.values(props.filters).some((value) => value !== ''));
const anySet = computed(() => applied.value || [form.search, form.event, form.from, form.to].some((value) => value !== ''));
// Nothing recorded yet: there is nothing to filter either.
const trailEmpty = computed(() => !applied.value && props.entries.data.length === 0 && props.entries.prev_page_url === null);

const familyOptions = computed(() => {
    const families = [...props.eventGroups];

    // A link from elsewhere can ask for one exact event rather than a family.
    if (props.filters.event !== '' && !families.includes(props.filters.event)) {
        families.push(props.filters.event);
    }

    return [
        { value: '', label: t('audit.filters.allFamilies') },
        ...families.map((family) => ({
            value: family,
            label: family.includes('.') ? eventLabel(family) : eventFamilyLabel(family),
        })),
    ];
});

function apply(): void {
    form.transform((data) =>
        // Leave unset filters out of the address, and with them the cursor of the page being left.
        Object.fromEntries(
            Object.entries(data)
                .map(([key, value]) => [key, value.trim()] as const)
                .filter(([, value]) => value !== ''),
        ),
    ).get(route('core.audit.index'), { preserveState: true, preserveScroll: true });
}

function clear(): void {
    form.search = '';
    form.event = '';
    form.from = '';
    form.to = '';
    form.clearErrors();

    if (applied.value) {
        apply();
    }
}

/*
 * An entry opens to show what it recorded beyond its headline. The table has
 * no slot for a row's details, so an opened entry is followed by a second row
 * that carries them (see the first cell in the template).
 */
type Row = (AuditEntry & { kind: 'entry' }) | { kind: 'details'; id: string; entry: AuditEntry };

const details = computed(() => new Map(props.entries.data.map((entry) => [entry.id, detailRows(entry.properties)])));
const expanded = ref(new Set<string>());

const rows = computed<Row[]>(() =>
    props.entries.data.flatMap((entry): Row[] => {
        const row: Row = { ...entry, kind: 'entry' };

        return expanded.value.has(entry.id) ? [row, { kind: 'details', id: detailsId(entry.id), entry }] : [row];
    }),
);

function detailsId(id: string): string {
    return `audit-details-${id}`;
}

function toggle(id: string): void {
    const next = new Set(expanded.value);

    if (!next.delete(id)) {
        next.add(id);
    }

    expanded.value = next;
}

watch(
    () => props.entries,
    () => {
        expanded.value = new Set();
    },
);

// Time and IP are as wide as what they hold; the people and records get a fixed share; the activity takes the rest.
const columns = computed<Column[]>(() => [
    { key: 'createdAt', label: t('audit.columns.time'), class: 'w-px whitespace-nowrap' },
    { key: 'event', label: t('audit.columns.event') },
    { key: 'actor', label: t('audit.columns.actor'), class: 'w-1/5' },
    { key: 'subject', label: t('audit.columns.subject'), class: 'w-1/5' },
    { key: 'ipAddress', label: t('audit.columns.ip'), class: 'w-px whitespace-nowrap', hideable: true },
]);
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('audit.title')"
        :description="t('audit.description', { workspace: page.props.tenant?.name })"
    />

    <Card flush>
        <form
            v-if="!trailEmpty"
            role="search"
            :aria-label="t('audit.filters.label')"
            class="grid grid-cols-2 gap-x-3 gap-y-4 p-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_10rem_10rem_auto]"
            novalidate
            @submit.prevent="apply"
        >
            <div class="col-span-2 sm:col-span-1">
                <Input
                    v-model="form.search"
                    name="search"
                    :label="t('audit.filters.search')"
                    :placeholder="t('audit.filters.searchPlaceholder')"
                    :error="form.errors.search"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    enterkeyhint="search"
                >
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
            </div>

            <div class="col-span-2 sm:col-span-1">
                <Select
                    v-model="form.event"
                    name="event"
                    :label="t('audit.filters.family')"
                    :options="familyOptions"
                    :error="form.errors.event"
                />
            </div>

            <!-- min-w-0: a date field must be allowed to get narrower than the browser would like on a phone. -->
            <div class="min-w-0">
                <Input
                    v-model="form.from"
                    type="date"
                    name="from"
                    :label="t('audit.filters.from')"
                    :max="form.to || undefined"
                    :error="form.errors.from"
                />
            </div>
            <div class="min-w-0">
                <Input
                    v-model="form.to"
                    type="date"
                    name="to"
                    :label="t('audit.filters.to')"
                    :min="form.from || undefined"
                    :error="form.errors.to"
                />
            </div>

            <div class="col-span-2 flex flex-col gap-1.5 xl:col-span-1">
                <!-- Stands in for a label, so the buttons sit level with the fields on one row. -->
                <span class="hidden text-[0.8125rem] font-medium xl:block" aria-hidden="true">&nbsp;</span>
                <div class="flex min-h-10 flex-wrap items-center gap-2">
                    <Button type="submit" :loading="form.processing">{{ t('audit.filters.apply') }}</Button>
                    <!-- On one row with the fields there is only room for the icon until the screen gets wide. -->
                    <Button v-if="anySet" variant="ghost" :disabled="form.processing" :title="t('audit.filters.clear')" @click="clear">
                        <span class="inline-flex items-center gap-2">
                            <FilterX class="size-4" aria-hidden="true" />
                            <span class="xl:max-2xl:sr-only">{{ t('audit.filters.clear') }}</span>
                        </span>
                    </Button>
                </div>
            </div>
        </form>

        <!-- A size container: an opened entry's details measure themselves against it (100cqw). -->
        <div
            :class="[
                '@container transition-opacity duration-200',
                trailEmpty ? 'pt-4' : 'border-t border-line pt-3',
                form.processing ? 'opacity-60' : '',
            ]"
            :aria-busy="form.processing || undefined"
        >
            <DataTable :columns="columns" :rows="rows" row-key="id" :caption="t('audit.caption')" storage-key="audit-log">
                <template #toolbar>
                    <p class="eyebrow">{{ applied ? t('audit.orderFiltered') : t('audit.order') }}</p>
                </template>

                <template #cell-createdAt="{ row }">
                    <!--
                        The details row. Its first cell holds the panel: the wrapper has no width of
                        its own, so the Time column keeps its size, and the panel inside is as wide as
                        the card and lies across the row's other cells, which are left empty.
                    -->
                    <div v-if="row.kind === 'details'" class="w-0">
                        <AuditDetails
                            :id="row.id"
                            class="w-[calc(100cqw-2.5rem)]"
                            :rows="details.get(row.entry.id) ?? []"
                            :event="row.entry.event"
                            :label="t('audit.details.toggle', { event: eventLabel(row.entry.event) })"
                        />
                    </div>
                    <time v-else :datetime="row.createdAt" class="block">
                        <span class="tabular block text-ink">{{ format.date(row.createdAt, 'datetime') }}</span>
                        <span class="block text-xs text-ink-subtle">{{ format.relative(row.createdAt) }}</span>
                    </time>
                </template>

                <template #cell-event="{ row }">
                    <!-- w-44 min-w-full: asks the column for 11rem, then fills whatever it gets. -->
                    <div v-if="row.kind === 'entry'" class="flex w-44 min-w-full items-center gap-1">
                        <span class="min-w-0 text-ink">{{ eventLabel(row.event) }}</span>
                        <Button
                            v-if="details.get(row.id)?.length"
                            icon
                            size="sm"
                            variant="ghost"
                            :aria-expanded="expanded.has(row.id)"
                            :aria-controls="expanded.has(row.id) ? detailsId(row.id) : undefined"
                            @click="toggle(row.id)"
                        >
                            <template #icon>
                                <ChevronDown
                                    :class="['size-4 transition-transform duration-200', expanded.has(row.id) ? 'rotate-180' : '']"
                                    aria-hidden="true"
                                />
                            </template>
                            {{ t('audit.details.toggle', { event: eventLabel(row.event) }) }}
                        </Button>
                    </div>
                </template>

                <template #cell-actor="{ row }">
                    <template v-if="row.kind === 'entry'">
                        <span v-if="row.actor" class="block w-32 min-w-full truncate text-ink-muted" :title="row.actor">{{ row.actor }}</span>
                        <span v-else class="whitespace-nowrap text-ink-subtle">{{ t('common.system') }}</span>
                    </template>
                </template>

                <template #cell-subject="{ row }">
                    <template v-if="row.kind === 'entry'">
                        <span v-if="row.subject" class="block w-32 min-w-full truncate text-ink-muted" :title="row.subject">{{ row.subject }}</span>
                        <span v-else class="text-ink-subtle">—</span>
                    </template>
                </template>

                <template #cell-ipAddress="{ row }">
                    <template v-if="row.kind === 'entry'">
                        <!-- Wide enough for any IPv4 address; an IPv6 one (up to 39 characters) wraps rather than take half the table. -->
                        <span v-if="row.ipAddress" class="inline-block w-30 font-mono text-xs break-all whitespace-normal text-ink-muted">
                            {{ row.ipAddress }}
                        </span>
                        <span v-else class="text-ink-subtle">—</span>
                    </template>
                </template>

                <template #empty>
                    <EmptyState v-if="applied" :title="t('audit.empty.filteredTitle')" :description="t('audit.empty.filteredBody')">
                        <template #icon><SearchX /></template>
                        <Button size="sm" @click="clear">
                            <template #icon><FilterX class="size-3.5" aria-hidden="true" /></template>
                            {{ t('audit.filters.clear') }}
                        </Button>
                    </EmptyState>
                    <EmptyState v-else :title="t('audit.empty.title')" :description="t('audit.empty.body')">
                        <template #icon><ScrollText /></template>
                    </EmptyState>
                </template>
            </DataTable>
        </div>

        <Pagination :meta="entries" />
    </Card>
</template>

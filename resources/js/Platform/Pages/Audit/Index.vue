<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ChevronDown, ScrollText, Search, SearchX, X } from 'lucide-vue-next';
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
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { eventLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';
import type { CursorPaginated } from '@/Core/types';
import { useAbilities } from '@/Platform/Components/abilities';

defineOptions({ layout: PlatformLayout });

/** A platform-level entry, or something an operator did inside a workspace. */
interface PlatformAuditEntry extends AuditEntry {
    /** The workspace the entry belongs to; null for a platform-level entry. */
    workspace: { id: string; name: string; slug: string } | null;
}

const props = defineProps<{
    /** Newest first, 30 to a page. */
    entries: CursorPaginated<PlatformAuditEntry>;
    /** The search as applied; an empty string means "not set". */
    filters: { search: string };
}>();

const { t } = useI18n();
const format = useFormat();
const { allows } = useAbilities();

/*
 * The search is applied on request, like the filters of a workspace's audit
 * log: the trail is long, and a query per keystroke is not worth it.
 */
const form = useForm({ search: props.filters.search });

const applied = computed(() => props.filters.search !== '');
// Nothing recorded yet: there is nothing to search either.
const trailEmpty = computed(() => !applied.value && props.entries.data.length === 0 && props.entries.prev_page_url === null);

// A very long query would push the empty state out of its card.
const shownQuery = computed(() => {
    const query = props.filters.search;

    return query.length > 40 ? `${query.slice(0, 40)}…` : query;
});

function apply(): void {
    form.transform((data) => {
        const search = data.search.trim();

        // Left out of the address when empty, and with it the cursor of the page being left.
        return search === '' ? {} : { search };
    }).get(route('platform.audit.index'), { preserveState: true, preserveScroll: true });
}

function clear(): void {
    form.search = '';
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
type Row = (PlatformAuditEntry & { kind: 'entry' }) | { kind: 'details'; id: string; entry: PlatformAuditEntry };

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

/** Audit events are named in the past tense; the ones that did not succeed end in `_failed`. */
function failed(event: string): boolean {
    return event.endsWith('_failed');
}

// Time and IP are as wide as what they hold; people, records and workspaces get a fixed share; the activity takes the rest.
const columns = computed<Column[]>(() => [
    { key: 'createdAt', label: t('platformAudit.columns.time'), class: 'w-px whitespace-nowrap' },
    { key: 'event', label: t('platformAudit.columns.event') },
    { key: 'actor', label: t('platformAudit.columns.actor'), class: 'w-1/6' },
    { key: 'subject', label: t('platformAudit.columns.subject'), class: 'w-1/6' },
    { key: 'workspace', label: t('platformAudit.columns.workspace'), class: 'w-1/6' },
    { key: 'ipAddress', label: t('platformAudit.columns.ip'), class: 'w-px whitespace-nowrap', hideable: true },
]);
</script>

<template>
    <PageHeader :eyebrow="t('shell.nav.sections.platform')" :title="t('shell.nav.platformAudit')" :description="t('platformAudit.description')" />

    <Card flush>
        <form
            v-if="!trailEmpty"
            role="search"
            :aria-label="t('platformAudit.search')"
            class="flex flex-wrap items-start gap-x-3 gap-y-3 p-5"
            novalidate
            @submit.prevent="apply"
        >
            <div class="w-full sm:max-w-md sm:flex-1">
                <Input
                    v-model="form.search"
                    name="search"
                    role="searchbox"
                    :aria-label="t('platformAudit.search')"
                    :placeholder="t('platformAudit.searchPlaceholder')"
                    :hint="t('platformAudit.searchHint')"
                    :error="form.errors.search"
                    maxlength="100"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    enterkeyhint="search"
                >
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    <template v-if="form.search !== ''" #trailing>
                        <Button size="sm" variant="ghost" icon :disabled="form.processing" @click="clear">
                            <template #icon><X class="size-4" aria-hidden="true" /></template>
                            {{ t('common.actions.clear') }}
                        </Button>
                    </template>
                </Input>
            </div>

            <Button type="submit" :loading="form.processing">{{ t('common.actions.search') }}</Button>
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
            <DataTable :columns="columns" :rows="rows" row-key="id" :caption="t('platformAudit.caption')" storage-key="platform-audit-log">
                <template #toolbar>
                    <p class="eyebrow">{{ applied ? t('platformAudit.orderFiltered') : t('platformAudit.order') }}</p>
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
                    <!-- w-48 min-w-full: asks the column for 12rem, then fills whatever it gets. -->
                    <div v-if="row.kind === 'entry'" class="flex w-48 min-w-full items-start gap-1">
                        <span class="flex min-w-0 flex-1 items-start gap-2.5">
                            <span
                                :class="['mt-1.75 size-1.5 shrink-0 rounded-full', failed(row.event) ? 'bg-warn' : 'bg-line-strong']"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <span class="block text-ink">{{ eventLabel(row.event) }}</span>
                                <!-- The event's own name: what the search above matches. -->
                                <span class="block font-mono text-xs break-all text-ink-subtle">{{ row.event }}</span>
                            </span>
                        </span>
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
                        <!-- No account and an address: someone who never got in. No address either: the platform itself. -->
                        <span v-else class="whitespace-nowrap text-ink-subtle">
                            {{ row.ipAddress ? t('platform.activity.noActor') : t('common.system') }}
                        </span>
                    </template>
                </template>

                <template #cell-subject="{ row }">
                    <template v-if="row.kind === 'entry'">
                        <span v-if="row.subject" class="block w-32 min-w-full truncate text-ink-muted" :title="row.subject">{{ row.subject }}</span>
                        <span v-else class="text-ink-subtle">—</span>
                    </template>
                </template>

                <template #cell-workspace="{ row }">
                    <template v-if="row.kind === 'entry'">
                        <template v-if="row.workspace">
                            <Link
                                v-if="allows('platform.tenants.view')"
                                :href="route('platform.tenants.show', { workspace: row.workspace.id })"
                                class="block w-32 min-w-full truncate rounded-chip text-ink underline-offset-4 hover:text-brand-text hover:underline"
                                :title="row.workspace.name"
                            >
                                {{ row.workspace.name }}
                            </Link>
                            <span v-else class="block w-32 min-w-full truncate text-ink" :title="row.workspace.name">{{ row.workspace.name }}</span>
                        </template>
                        <span v-else class="whitespace-nowrap text-ink-subtle">{{ t('platformAudit.platformLevel') }}</span>
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
                    <EmptyState
                        v-if="applied"
                        :title="t('platformAudit.empty.filteredTitle', { query: shownQuery })"
                        :description="t('platformAudit.empty.filteredBody')"
                    >
                        <template #icon><SearchX /></template>
                        <Button size="sm" @click="clear">{{ t('common.actions.clear') }}</Button>
                    </EmptyState>
                    <EmptyState v-else :title="t('platformAudit.empty.title')" :description="t('platformAudit.empty.body')">
                        <template #icon><ScrollText /></template>
                    </EmptyState>
                </template>
            </DataTable>
        </div>

        <Pagination :meta="entries" />
    </Card>
</template>

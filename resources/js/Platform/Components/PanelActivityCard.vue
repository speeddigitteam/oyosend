<script setup lang="ts">
import { ScrollText } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { eventLabel } from '@/Core/lib/labels';

/** A platform-level audit entry: one that belongs to no workspace. */
export interface ActivityEntry {
    id: string;
    event: string;
    /** Email of the account that acted; null when nobody was signed in. */
    actor: string | null;
    subject: string | null;
    /** Null when the entry was not written during a web request. */
    ipAddress: string | null;
    createdAt: string;
}

defineProps<{ entries: ActivityEntry[] }>();

const { t } = useI18n();
const format = useFormat();

// Time first, as in a workspace's audit log.
const columns = computed<Column[]>(() => [
    { key: 'createdAt', label: t('platform.activity.columns.when'), class: 'w-px whitespace-nowrap' },
    { key: 'event', label: t('platform.activity.columns.event'), class: 'min-w-56' },
    { key: 'actor', label: t('platform.activity.columns.actor'), class: 'whitespace-nowrap' },
    { key: 'ipAddress', label: t('platform.activity.columns.ip'), class: 'whitespace-nowrap' },
]);

/** Audit events are named in the past tense; the ones that did not succeed end in `_failed`. */
function failed(event: string): boolean {
    return event.endsWith('_failed');
}
</script>

<template>
    <Card :title="t('platform.activity.title')" :description="t('platform.activity.description')" flush>
        <EmptyState
            v-if="entries.length === 0"
            :title="t('platform.activity.empty')"
            :description="t('platform.activity.emptyBody')"
            compact
        >
            <template #icon><ScrollText /></template>
        </EmptyState>

        <DataTable v-else :columns="columns" :rows="entries" row-key="id" :caption="t('platform.activity.caption')">
            <template #cell-createdAt="{ row }">
                <time :datetime="row.createdAt" :title="format.date(row.createdAt, 'datetime')" class="tabular text-ink-muted">
                    {{ format.relative(row.createdAt) }}
                </time>
            </template>

            <template #cell-event="{ row }">
                <span class="flex items-start gap-2.5">
                    <span
                        :class="['mt-1.75 size-1.5 shrink-0 rounded-full', failed(row.event) ? 'bg-warn' : 'bg-line-strong']"
                        aria-hidden="true"
                    />
                    <!-- The tooltip gives the event's own name, which is what the audit trail is searched by. -->
                    <span class="min-w-0 text-ink" :title="row.event">
                        {{ eventLabel(row.event) }}
                        <span v-if="row.subject" class="text-ink-muted"> · {{ row.subject }}</span>
                    </span>
                </span>
            </template>

            <template #cell-actor="{ row }">
                <span v-if="row.actor" class="text-ink-muted">{{ row.actor }}</span>
                <!-- No account and an address: someone who never got in. No address either: the platform itself. -->
                <span v-else class="text-ink-subtle">{{ row.ipAddress ? t('platform.activity.noActor') : t('common.system') }}</span>
            </template>

            <template #cell-ipAddress="{ row }">
                <span v-if="row.ipAddress" class="font-mono text-xs leading-5.25 text-ink-muted">{{ row.ipAddress }}</span>
                <span v-else class="text-ink-subtle">—</span>
            </template>
        </DataTable>
    </Card>
</template>

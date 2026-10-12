<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { History, Plus, Send, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import MessageStatusBadge from '../../Components/MessageStatusBadge.vue';
import type { InteractionType, MessageStatus, NameRef } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

interface EntryRow {
    id: string;
    type: InteractionType;
    direction: 'inbound' | 'outbound' | null;
    summary: string;
    body: string | null;
    occurredAt: string;
    sourceModule: string | null;
    sourceType: string | null;
    sourceId: string | null;
    contactId: string | null;
    messageId: string | null;
    messageStatus: MessageStatus | null;
    owner: NameRef | null;
    deletable: boolean;
}

const props = defineProps<{
    subject: { type: 'contact' | 'company'; id: string; name: string; email: string | null; phone: string | null };
    entries: Paginated<EntryRow>;
    filters: { type: string | null };
    types: string[];
    canLog: boolean;
    canCompose: boolean;
}>();

const ALL_TYPES: InteractionType[] = ['note', 'call', 'meeting', 'email', 'sms', 'whatsapp', 'system'];

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
const { label } = useCommunicationLabels();

const typeModel = computed<string>({
    get: () => props.filters.type ?? '',
    set: (value) => {
        router.get(timelineUrl(), value === '' ? {} : { type: value }, { preserveState: true });
    },
});

const typeFilterOptions = computed(() => [
    { value: '', label: t('communication.timeline.anyType') },
    // The filter covers every kind of entry; only the manual kinds can be logged by hand.
    ...ALL_TYPES.map((value) => ({ value, label: label('interaction', value) })),
]);
const typeOptions = computed(() => props.types.map((value) => ({ value, label: label('interaction', value) })));
const directionOptions = computed(() => [
    { value: '', label: t('communication.timeline.directionNone') },
    { value: 'inbound', label: t('communication.direction.inbound') },
    { value: 'outbound', label: t('communication.direction.outbound') },
]);

function timelineUrl(): string {
    return props.subject.type === 'contact'
        ? route('communication.timeline.contact.show', { contact: props.subject.id })
        : route('communication.timeline.company.show', { company: props.subject.id });
}

const form = useForm({ type: props.types[0] ?? 'note', direction: '', summary: '', body: '', occurred_at: '' });

function log(): void {
    const url =
        props.subject.type === 'contact'
            ? route('communication.timeline.contact.store', { contact: props.subject.id })
            : route('communication.timeline.company.store', { company: props.subject.id });

    form
        .transform((data) => ({
            type: data.type,
            direction: data.direction === '' ? null : data.direction,
            summary: data.summary,
            body: data.body.trim() === '' ? null : data.body,
            occurred_at: data.occurred_at === '' ? null : data.occurred_at,
        }))
        .post(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('summary', 'body', 'occurred_at', 'direction');
            },
        });
}

const removing = ref<EntryRow | null>(null);
const removePending = ref(false);

function remove(): void {
    if (!removing.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('communication.timeline.destroy', { interaction: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
        },
        onFinish: () => {
            removePending.value = false;
        },
    });
}

const kind = computed(() => t(props.subject.type === 'contact' ? 'communication.timeline.kindContact' : 'communication.timeline.kindCompany'));
</script>

<template>
    <PageHeader
        :eyebrow="t('communication.eyebrow')"
        :title="t('communication.timeline.titleFor', { name: subject.name })"
        :description="t('communication.timeline.description', { kind })"
    >
        <template v-if="canCompose && subject.type === 'contact'" #actions>
            <Button variant="secondary" :href="route('communication.messages.create', { contact: subject.id })">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('communication.timeline.sendMessage') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card>
                <Select v-model="typeModel" :options="typeFilterOptions" :label="t('communication.timeline.typeLabel')" />
            </Card>

            <Card v-if="entries.data.length === 0" flush>
                <EmptyState
                    :title="t(filters.type ? 'communication.timeline.emptyFiltered' : 'communication.timeline.empty')"
                    :description="filters.type ? undefined : t('communication.timeline.emptyBody')"
                >
                    <template #icon><History /></template>
                </EmptyState>
            </Card>
            <Card v-else flush>
                <ol class="divide-y divide-line" :aria-label="t('communication.timeline.list')">
                    <li v-for="entry in entries.data" :key="entry.id" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge tone="brand">{{ label('interaction', entry.type) }}</Badge>
                                    <Badge v-if="entry.direction" tone="neutral">{{ label('direction', entry.direction) }}</Badge>
                                    <MessageStatusBadge v-if="entry.messageStatus" :status="entry.messageStatus" />
                                </div>
                                <p class="mt-2 text-sm font-medium wrap-anywhere text-ink">{{ entry.summary }}</p>
                                <p v-if="entry.body" class="mt-1 text-sm whitespace-pre-wrap wrap-anywhere text-ink-muted">{{ entry.body }}</p>
                                <p class="tabular mt-2 flex flex-wrap gap-x-3 text-xs text-ink-subtle">
                                    <span>{{ format.date(entry.occurredAt, 'datetime') }}</span>
                                    <span v-if="entry.owner">{{ t('communication.timeline.by', { name: entry.owner.name }) }}</span>
                                    <span v-else-if="entry.sourceModule">{{ t('communication.timeline.fromModule', { module: entry.sourceModule }) }}</span>
                                    <Link
                                        v-if="entry.messageId && can('communication.messages.view')"
                                        :href="route('communication.messages.show', { message: entry.messageId })"
                                        class="text-brand-text hover:underline"
                                    >
                                        {{ t('communication.timeline.viewMessage') }}
                                    </Link>
                                </p>
                            </div>
                            <Button
                                v-if="entry.deletable"
                                icon
                                size="sm"
                                variant="ghost"
                                :aria-label="t('communication.timeline.deleteFor', { summary: entry.summary })"
                                @click="removing = entry"
                            >
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            </Button>
                        </div>
                    </li>
                </ol>
            </Card>
            <Pagination :meta="entries" />
        </div>

        <Card v-if="canLog" :title="t('communication.timeline.log')" :description="t('communication.timeline.logDescription')" class="content-start">
            <form class="grid gap-4" @submit.prevent="log">
                <Select v-model="form.type" :options="typeOptions" :label="t('communication.timeline.type')" :error="form.errors.type" />
                <Select v-model="form.direction" :options="directionOptions" :label="t('communication.timeline.direction')" :error="form.errors.direction" optional />
                <Input
                    v-model="form.summary"
                    :label="t('communication.timeline.summary')"
                    :hint="t('communication.timeline.summaryHint')"
                    :error="form.errors.summary"
                    required
                    maxlength="200"
                />
                <Textarea v-model="form.body" :rows="4" :label="t('communication.timeline.details')" :error="form.errors.body" optional maxlength="5000" />
                <Input
                    v-model="form.occurred_at"
                    type="datetime-local"
                    :label="t('communication.timeline.occurredAt')"
                    :hint="t('communication.timeline.occurredAtHint')"
                    :error="form.errors.occurred_at"
                    optional
                />
                <Button type="submit" variant="primary" :loading="form.processing">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('communication.timeline.add') }}
                </Button>
            </form>
        </Card>
    </div>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('communication.timeline.deleteTitle')"
        :description="t('communication.timeline.deleteBody')"
        :confirm-label="t('communication.common.remove')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removing = null)"
        @confirm="remove"
    />
</template>

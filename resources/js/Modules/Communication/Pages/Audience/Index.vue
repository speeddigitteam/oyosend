<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ban, Plus, Search, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import type { ContactOption, NameRef } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

interface ConsentRow {
    id: string;
    contact: NameRef | null;
    channel: string;
    purpose: string;
    status: 'opted_in' | 'opted_out';
    method: string;
    evidence: string | null;
    recordedAt: string;
}

interface SuppressionRow {
    id: string;
    channel: string;
    address: string;
    scope: string;
    reason: string;
    note: string | null;
    contactId: string | null;
    removable: boolean;
    createdAt: string;
}

const props = defineProps<{
    consents: Paginated<ConsentRow>;
    suppressions: Paginated<SuppressionRow>;
    filters: { search: string; status: string | null; channel: string | null };
    contacts: ContactOption[];
    methods: string[];
    channels: string[];
    scopes: string[];
    canManageConsent: boolean;
    canManageSuppressions: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const { label } = useCommunicationLabels();

const draft = reactive({ search: props.filters.search, status: props.filters.status ?? '', channel: props.filters.channel ?? '' });

const statusOptions = computed(() => [
    { value: '', label: t('communication.audience.anyStatus') },
    { value: 'opted_in', label: t('communication.audience.consentStatus.opted_in') },
    { value: 'opted_out', label: t('communication.audience.consentStatus.opted_out') },
]);
const channelFilterOptions = computed(() => [
    { value: '', label: t('communication.audience.anyChannel') },
    ...props.channels.map((value) => ({ value, label: label('channel', value) })),
]);
const channelOptions = computed(() => props.channels.map((value) => ({ value, label: label('channel', value) })));
const contactOptions = computed(() => props.contacts.map((contact) => ({ value: contact.id, label: contact.name })));
const methodOptions = computed(() => props.methods.map((value) => ({ value, label: label('audience.methods', value) })));
const scopeOptions = computed(() => props.scopes.map((value) => ({ value, label: label('audience.scopeName', value) })));
const decisionOptions = computed(() => statusOptions.value.slice(1));

const consentColumns = computed<Column[]>(() => [
    { key: 'contact', label: t('communication.audience.contact') },
    { key: 'channel', label: t('communication.common.channel') },
    { key: 'status', label: t('communication.audience.decision') },
    { key: 'method', label: t('communication.audience.method'), hideable: true },
    { key: 'recordedAt', label: t('communication.audience.recordedAt') },
]);

const suppressionColumns = computed<Column[]>(() => [
    { key: 'address', label: t('communication.audience.address') },
    { key: 'channel', label: t('communication.common.channel') },
    { key: 'scope', label: t('communication.audience.scope') },
    { key: 'reason', label: t('communication.audience.reason') },
    { key: 'createdAt', label: t('communication.audience.added'), hideable: true },
    { key: 'actions', label: t('communication.common.actions'), srOnly: true, align: 'right' },
]);

function visit(): void {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries({ search: draft.search.trim(), status: draft.status, channel: draft.channel })) {
        if (value !== '') {
            query[key] = value;
        }
    }

    router.get(route('communication.audience.index'), query, { preserveState: true });
}

// Recording consent.
const consentOpen = ref(false);
const consentForm = useForm({ contact_id: '', channel: 'email', status: 'opted_in', method: 'form', evidence: '' });

function openConsent(): void {
    consentForm.defaults({ contact_id: '', channel: props.channels[0] ?? 'email', status: 'opted_in', method: props.methods[0] ?? 'form', evidence: '' }).reset();
    consentForm.clearErrors();
    consentOpen.value = true;
}

function saveConsent(): void {
    if (consentForm.contact_id === '') {
        return;
    }

    consentForm
        .transform((data) => ({ channel: data.channel, status: data.status, method: data.method, evidence: data.evidence.trim() === '' ? null : data.evidence }))
        .post(route('communication.consent.store', { contact: consentForm.contact_id }), {
            preserveScroll: true,
            onSuccess: () => {
                consentOpen.value = false;
            },
        });
}

// Suppressions.
const suppressionOpen = ref(false);
const suppressionForm = useForm({ channel: 'email', address: '', scope: 'all', note: '' });
const removing = ref<SuppressionRow | null>(null);
const removePending = ref(false);

function openSuppression(): void {
    suppressionForm.defaults({ channel: props.channels[0] ?? 'email', address: '', scope: props.scopes[0] ?? 'all', note: '' }).reset();
    suppressionForm.clearErrors();
    suppressionOpen.value = true;
}

function saveSuppression(): void {
    suppressionForm
        .transform((data) => ({ ...data, note: data.note.trim() === '' ? null : data.note }))
        .post(route('communication.suppressions.store'), {
            preserveScroll: true,
            onSuccess: () => {
                suppressionOpen.value = false;
            },
        });
}

function removeSuppression(): void {
    if (!removing.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('communication.suppressions.destroy', { suppression: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
        },
        onFinish: () => {
            removePending.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.audience.title')" :description="t('communication.audience.description')" />
    <CommunicationNav current="audience" />

    <div class="grid gap-3">
        <Card>
            <form role="search" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end" @submit.prevent="visit">
                <Input v-model="draft.search" :label="t('communication.audience.search')" maxlength="100">
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
                <Select v-model="draft.status" :options="statusOptions" :label="t('communication.audience.decision')" />
                <Select v-model="draft.channel" :options="channelFilterOptions" :label="t('communication.common.channel')" />
                <Button type="submit">{{ t('communication.common.apply') }}</Button>
            </form>
        </Card>

        <Card :title="t('communication.audience.consents')" :description="t('communication.audience.consentsHint')" flush>
            <template v-if="canManageConsent" #actions>
                <Button size="sm" variant="primary" @click="openConsent">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('communication.audience.recordConsent') }}
                </Button>
            </template>
            <DataTable :columns="consentColumns" :rows="consents.data" row-key="id" :caption="t('communication.audience.consentCaption')">
                <template #cell-contact="{ row }">
                    <span class="text-ink">{{ row.contact?.name ?? t('communication.audience.unknownContact') }}</span>
                    <span v-if="row.evidence" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.evidence }}</span>
                </template>
                <template #cell-channel="{ row }">{{ label('channel', row.channel) }}</template>
                <template #cell-status="{ row }">
                    <Badge :tone="row.status === 'opted_in' ? 'ok' : 'neutral'" dot>{{ t(`communication.audience.consentStatus.${row.status}`) }}</Badge>
                </template>
                <template #cell-method="{ row }">{{ label('audience.methods', row.method) }}</template>
                <template #cell-recordedAt="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.recordedAt, 'datetime') }}</span></template>
                <template #empty>
                    <EmptyState :title="t('communication.audience.consentEmpty')" :description="t('communication.audience.consentEmptyBody')" compact>
                        <template #icon><ShieldCheck /></template>
                    </EmptyState>
                </template>
            </DataTable>
            <div class="px-5 pb-3"><Pagination :meta="consents" /></div>
        </Card>

        <Card :title="t('communication.audience.suppressions')" :description="t('communication.audience.suppressionsHint')" flush>
            <template v-if="canManageSuppressions" #actions>
                <Button size="sm" variant="primary" @click="openSuppression">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('communication.audience.addSuppression') }}
                </Button>
            </template>
            <DataTable :columns="suppressionColumns" :rows="suppressions.data" row-key="id" :caption="t('communication.audience.suppressionCaption')">
                <template #cell-address="{ row }">
                    <span class="font-mono text-xs wrap-anywhere text-ink">{{ row.address }}</span>
                    <span v-if="row.note" class="block max-w-md truncate text-xs text-ink-subtle">{{ row.note }}</span>
                </template>
                <template #cell-channel="{ row }">{{ label('channel', row.channel) }}</template>
                <template #cell-scope="{ row }">
                    <Badge :tone="row.scope === 'all' ? 'warn' : 'neutral'">{{ label('audience.scopeName', row.scope) }}</Badge>
                </template>
                <template #cell-reason="{ row }">{{ label('audience.reasonName', row.reason) }}</template>
                <template #cell-createdAt="{ row }"><span class="tabular text-ink-muted">{{ format.date(row.createdAt, 'datetime') }}</span></template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-end">
                        <Button
                            v-if="canManageSuppressions && row.removable"
                            icon
                            size="sm"
                            variant="ghost"
                            :aria-label="t('communication.audience.removeFor', { address: row.address })"
                            @click="removing = row"
                        >
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <span v-else-if="!row.removable" class="text-xs text-ink-subtle">{{ t('communication.audience.notRemovable') }}</span>
                    </div>
                </template>
                <template #empty>
                    <EmptyState :title="t('communication.audience.suppressionEmpty')" :description="t('communication.audience.suppressionEmptyBody')" compact>
                        <template #icon><Ban /></template>
                    </EmptyState>
                </template>
            </DataTable>
            <div class="px-5 pb-3"><Pagination :meta="suppressions" /></div>
        </Card>
    </div>

    <Modal
        v-model:open="consentOpen"
        size="lg"
        :title="t('communication.audience.consentTitle')"
        :description="t('communication.audience.consentDescription')"
        :persistent="consentForm.processing"
    >
        <form id="communication-consent-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveConsent">
            <div class="sm:col-span-2">
                <Select
                    v-model="consentForm.contact_id"
                    :options="contactOptions"
                    :label="t('communication.audience.contact')"
                    :placeholder="t('communication.audience.contactPlaceholder')"
                    :error="consentForm.errors.contact_id"
                    required
                />
            </div>
            <Select v-model="consentForm.channel" :options="channelOptions" :label="t('communication.common.channel')" :error="consentForm.errors.channel" />
            <Select v-model="consentForm.status" :options="decisionOptions" :label="t('communication.audience.decision')" :error="consentForm.errors.status" />
            <div class="sm:col-span-2">
                <Select v-model="consentForm.method" :options="methodOptions" :label="t('communication.audience.method')" :error="consentForm.errors.method" />
            </div>
            <div class="sm:col-span-2">
                <Textarea
                    v-model="consentForm.evidence"
                    :rows="3"
                    :label="t('communication.audience.evidence')"
                    :hint="t('communication.audience.evidenceHint')"
                    :error="consentForm.errors.evidence"
                    :optional="consentForm.status !== 'opted_in'"
                    maxlength="500"
                />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="consentForm.processing" @click="consentOpen = false">{{ t('communication.common.cancel') }}</Button>
            <Button form="communication-consent-form" type="submit" variant="primary" :loading="consentForm.processing">{{ t('communication.common.save') }}</Button>
        </template>
    </Modal>

    <Modal
        v-model:open="suppressionOpen"
        :title="t('communication.audience.suppressionTitle')"
        :description="t('communication.audience.suppressionDescription')"
        :persistent="suppressionForm.processing"
    >
        <form id="communication-suppression-form" class="grid gap-4" @submit.prevent="saveSuppression">
            <Select v-model="suppressionForm.channel" :options="channelOptions" :label="t('communication.common.channel')" :error="suppressionForm.errors.channel" />
            <Input
                v-model="suppressionForm.address"
                :label="t('communication.audience.address')"
                :hint="t('communication.audience.addressHint')"
                :error="suppressionForm.errors.address"
                required
                mono
                maxlength="190"
            />
            <Select v-model="suppressionForm.scope" :options="scopeOptions" :label="t('communication.audience.scope')" :error="suppressionForm.errors.scope" />
            <Input v-model="suppressionForm.note" :label="t('communication.audience.note')" :error="suppressionForm.errors.note" optional maxlength="300" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="suppressionForm.processing" @click="suppressionOpen = false">{{ t('communication.common.cancel') }}</Button>
            <Button form="communication-suppression-form" type="submit" variant="primary" :loading="suppressionForm.processing">{{ t('communication.common.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('communication.audience.removeTitle')"
        :description="t('communication.audience.removeBody', { address: removing?.address ?? '' })"
        :confirm-label="t('communication.common.remove')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removing = null)"
        @confirm="removeSuppression"
    />
</template>

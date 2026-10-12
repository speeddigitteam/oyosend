<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import EmailBadge from './EmailBadge.vue';
import type { MemberRow } from './types';
import { useEmailLabels } from './useEmailLabels';

/**
 * A page of list members with what the platform says about each address right now and the changes a person
 * may make: record consent evidence for a pending member, close a membership, or remove it.
 */
const props = defineProps<{
    members: MemberRow[];
    consentMethods: string[];
    canManage: boolean;
    showList?: boolean;
    filtered?: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const labels = useEmailLabels();

const confirming = ref<MemberRow | null>(null);
const removing = ref<MemberRow | null>(null);
const closing = ref<MemberRow | null>(null);
const busy = ref(false);

const consent = useForm({ action: 'confirm', consent_method: 'website_form', consent_evidence: '', consented_at: '' });
const methodOptions = computed(() => props.consentMethods.map((value) => ({ value, label: t(`emailMarketing.consentMethod.${value}`) })));
const evidenceInvalid = computed(() => consent.consent_evidence.trim().length < 5);

const columns = computed<Column[]>(() => [
    { key: 'contact', label: t('emailMarketing.fields.contact') },
    ...(props.showList ? [{ key: 'list', label: t('emailMarketing.fields.list'), hideable: true }] : []),
    { key: 'status', label: t('emailMarketing.fields.status') },
    { key: 'platform', label: t('emailMarketing.fields.platform'), hideable: true },
    { key: 'since', label: t('emailMarketing.fields.since'), hideable: true },
    { key: 'actions', label: t('emailMarketing.common.actions'), srOnly: true, align: 'right' },
]);

function submitConsent(): void {
    if (confirming.value === null) {
        return;
    }

    consent
        .transform((data) => ({
            action: 'confirm',
            consent_method: data.consent_method,
            consent_evidence: data.consent_evidence.trim(),
            consented_at: data.consented_at === '' ? null : data.consented_at,
        }))
        .put(route('email_marketing.subscriptions.update', { subscription: confirming.value.id }), {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = null;
                consent.reset();
            },
        });
}

function unsubscribe(): void {
    if (closing.value === null) {
        return;
    }

    busy.value = true;
    router.post(route('email_marketing.subscriptions.unsubscribe', { subscription: closing.value.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            closing.value = null;
        },
    });
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('email_marketing.subscriptions.destroy', { subscription: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            removing.value = null;
        },
    });
}

function since(member: MemberRow): string | null {
    return member.unsubscribedAt ?? member.subscribedAt;
}
</script>

<template>
    <DataTable :columns="columns" :rows="members" row-key="id" :caption="t('emailMarketing.members.caption')">
        <template #cell-contact="{ row }">
            <span class="text-ink">{{ row.contact.name }}</span>
            <span class="block text-xs text-ink-subtle">{{ row.contact.email ?? t('emailMarketing.common.dash') }}</span>
        </template>
        <template #cell-list="{ row }"><span class="text-ink-muted">{{ row.listName }}</span></template>
        <template #cell-status="{ row }">
            <EmailBadge kind="subscription" :value="row.status" />
            <span v-if="row.status === 'pending'" class="mt-1 block text-xs text-ink-subtle">{{ t('emailMarketing.members.pendingHint') }}</span>
        </template>
        <template #cell-platform="{ row }">
            <span v-if="row.platform === null" class="text-ink-subtle">{{ t('emailMarketing.common.dash') }}</span>
            <span v-else-if="row.platform.allowed" class="text-ok">{{ t('emailMarketing.members.canReceive') }}</span>
            <span v-else class="text-ink-muted">{{ labels.reason(row.platform.reason) }}</span>
        </template>
        <template #cell-since="{ row }">
            <span class="tabular text-ink-muted">{{ since(row) ? format.date(since(row), 'date') : t('emailMarketing.common.dash') }}</span>
        </template>
        <template #cell-actions="{ row }">
            <span v-if="canManage" class="flex justify-end gap-1">
                <Button v-if="row.status === 'pending'" size="sm" variant="ghost" @click="confirming = row">{{ t('emailMarketing.members.recordConsent') }}</Button>
                <Button v-if="row.status === 'pending' || row.status === 'subscribed'" size="sm" variant="ghost" @click="closing = row">
                    {{ t('emailMarketing.members.unsubscribe') }}
                </Button>
                <Button size="sm" variant="ghost" @click="removing = row">{{ t('emailMarketing.members.remove') }}</Button>
            </span>
        </template>
        <template #empty>
            <EmptyState
                :title="filtered ? t('emailMarketing.common.noMatches') : t('emailMarketing.members.empty')"
                :description="filtered ? t('emailMarketing.common.noMatchesBody') : t('emailMarketing.members.emptyBody')"
                compact
            >
                <template #icon><Users /></template>
            </EmptyState>
        </template>
    </DataTable>

    <Modal :open="confirming !== null" :title="t('emailMarketing.members.recordConsent')" :description="t('emailMarketing.members.consentBody')" @update:open="confirming = $event ? confirming : null">
        <form id="member-consent" class="grid gap-4" novalidate @submit.prevent="submitConsent">
            <Select v-model="consent.consent_method" :options="methodOptions" :label="t('emailMarketing.fields.consentMethod')" :error="consent.errors.consent_method" />
            <Textarea
                v-model="consent.consent_evidence"
                :label="t('emailMarketing.fields.consentEvidence')"
                :hint="t('emailMarketing.members.evidenceHint')"
                maxlength="500"
                :error="consent.errors.consent_evidence"
            />
            <Input v-model="consent.consented_at" type="date" :label="t('emailMarketing.fields.consentedAt')" optional :error="consent.errors.consented_at" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="confirming = null">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button type="submit" form="member-consent" variant="primary" :loading="consent.processing" :disabled="evidenceInvalid">
                {{ t('emailMarketing.common.save') }}
            </Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="closing !== null"
        :title="t('emailMarketing.members.unsubscribeTitle')"
        :description="t('emailMarketing.members.unsubscribeBody')"
        :confirm-label="t('emailMarketing.members.unsubscribe')"
        :loading="busy"
        @update:open="closing = $event ? closing : null"
        @confirm="unsubscribe"
    />
    <ConfirmDialog
        :open="removing !== null"
        :title="t('emailMarketing.members.removeTitle')"
        :description="t('emailMarketing.members.removeBody')"
        :confirm-label="t('emailMarketing.members.remove')"
        :loading="busy"
        @update:open="removing = $event ? removing : null"
        @confirm="remove"
    />
</template>

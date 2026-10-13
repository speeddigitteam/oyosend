<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { KNOWN_REASONS, LEAD_TONES, type LeadLogRow, type LeadRow } from './leadAds';

/** One lead's journey: received, fetched, then handed to the CRM or stopped, each step with its reason. */
const props = defineProps<{ open: boolean; lead: LeadRow | null; log: LeadLogRow[]; canManage: boolean }>();
const emit = defineEmits<{ 'update:open': [value: boolean]; retry: [lead: LeadRow] }>();

const { t } = useI18n();
const format = useFormat();

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });

function reason(code: string | null, fallback: string | null): string | null {
    if (code !== null && KNOWN_REASONS.includes(code)) {
        return t(`leadAds.reasons.${code}`);
    }

    return fallback;
}

function entry(row: LeadLogRow): string {
    return reason(row.code, row.message) ?? t(`leadAds.log.defaults.${row.step}`);
}
</script>

<template>
    <Modal v-model:open="model" size="lg" :title="t('leadAds.log.title')" :description="lead ? t('leadAds.log.description', { when: format.date(lead.receivedAt, 'datetime') }) : undefined">
        <div v-if="lead" class="grid gap-4" data-testid="lead-log">
            <div class="flex flex-wrap items-center gap-2">
                <Badge :tone="LEAD_TONES[lead.status]" dot>{{ t(`leadAds.status.${lead.status}`) }}</Badge>
                <span class="text-[0.8125rem] text-ink-muted">{{ lead.accountName }}<template v-if="lead.formName"> · {{ lead.formName }}</template></span>
            </div>

            <p v-if="lead.reasonCode || lead.reason" class="text-[0.8125rem] text-ink-muted" data-testid="lead-reason">
                {{ reason(lead.reasonCode, lead.reason) }}
            </p>

            <dl v-if="lead.crmLeadId || lead.crmContactId" class="grid gap-2 text-[0.8125rem] sm:grid-cols-2" data-testid="lead-crm">
                <div v-if="lead.crmContactId" class="min-w-0">
                    <dt class="eyebrow">{{ t('leadAds.leads.crmContact') }}</dt>
                    <dd class="font-mono text-xs break-all text-ink-muted">{{ lead.crmContactId }}</dd>
                </div>
                <div v-if="lead.crmLeadId" class="min-w-0">
                    <dt class="eyebrow">{{ t('leadAds.leads.crmLead') }}</dt>
                    <dd class="font-mono text-xs break-all text-ink-muted">{{ lead.crmLeadId }}</dd>
                </div>
            </dl>

            <ol class="grid gap-0 border-l border-line-strong pl-4" data-testid="lead-steps">
                <li v-for="row in log" :key="row.id" class="relative pb-4 last:pb-0">
                    <span class="absolute top-1.5 -left-[1.3125rem] size-2.5 rounded-full bg-brand" aria-hidden="true" />
                    <p class="text-sm font-medium text-ink">{{ t(`leadAds.log.steps.${row.step}`) }}</p>
                    <p class="text-[0.8125rem] break-words text-ink-muted">{{ entry(row) }}</p>
                    <p class="tabular text-xs text-ink-subtle">{{ format.date(row.at, 'datetime') }}</p>
                </li>
            </ol>

            <dl class="grid gap-2 text-xs text-ink-subtle sm:grid-cols-2">
                <div v-if="lead.adId" class="min-w-0"><dt class="eyebrow">{{ t('leadAds.leads.ad') }}</dt><dd class="font-mono break-all">{{ lead.adId }}</dd></div>
                <div v-if="lead.campaignRef" class="min-w-0"><dt class="eyebrow">{{ t('leadAds.leads.campaign') }}</dt><dd class="font-mono break-all">{{ lead.campaignRef }}</dd></div>
            </dl>
        </div>
        <template #footer>
            <Button variant="ghost" @click="model = false">{{ t('leadAds.common.close') }}</Button>
            <Button v-if="lead && canManage && lead.canRetry" variant="primary" data-testid="log-retry" @click="emit('retry', lead)">{{ t('leadAds.leads.retry') }}</Button>
        </template>
    </Modal>
</template>

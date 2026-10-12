<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SupportNav from '../../Components/SupportNav.vue';
import type { Option, TicketPriority } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: {
        ticketPrefix: string;
        numberPadding: number;
        defaultPriority: TicketPriority;
        defaultQueueId: string | null;
        defaultSlaPolicyId: string | null;
        csatEnabled: boolean;
        csatExpiryDays: number;
        csatSendEmail: boolean;
        autoCloseDays: number | null;
    };
    queues: Option[];
    policies: Option[];
    priorities: TicketPriority[];
}>();

const { t } = useI18n();

const form = useForm({
    ticket_prefix: props.settings.ticketPrefix,
    number_padding: String(props.settings.numberPadding),
    default_priority: props.settings.defaultPriority as string,
    default_queue_id: props.settings.defaultQueueId ?? '',
    default_sla_policy_id: props.settings.defaultSlaPolicyId ?? '',
    csat_enabled: props.settings.csatEnabled,
    csat_expiry_days: String(props.settings.csatExpiryDays),
    csat_send_email: props.settings.csatSendEmail,
    auto_close_days: props.settings.autoCloseDays === null ? '' : String(props.settings.autoCloseDays),
});

const priorityOptions = computed(() => props.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) })));
const queueOptions = computed(() => [{ value: '', label: t('support.settings.noDefaultQueue') }, ...props.queues.map((queue) => ({ value: queue.id, label: queue.name }))]);
const policyOptions = computed(() => [{ value: '', label: t('support.settings.noDefaultPolicy') }, ...props.policies.map((policy) => ({ value: policy.id, label: policy.name }))]);

/** What the next ticket number will look like, so the two numbering fields are not abstract. */
const example = computed(() => {
    const padding = Math.min(10, Math.max(1, Number(form.number_padding) || 1));

    return `${form.ticket_prefix}${'1'.padStart(padding, '0')}`;
});

function save(): void {
    form.transform((data) => ({
        ...data,
        number_padding: Number(data.number_padding),
        csat_expiry_days: Number(data.csat_expiry_days),
        default_queue_id: data.default_queue_id === '' ? null : data.default_queue_id,
        default_sla_policy_id: data.default_sla_policy_id === '' ? null : data.default_sla_policy_id,
        auto_close_days: data.auto_close_days === '' ? null : Number(data.auto_close_days),
    })).put(route('support.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.settings.title')" :description="t('support.settings.description')" />
    <SupportNav current="settings" />

    <form class="grid max-w-3xl gap-3" @submit.prevent="save">
        <Card :title="t('support.settings.numbering')" :description="t('support.settings.numberingHint')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.ticket_prefix" :label="t('support.settings.prefix')" :error="form.errors.ticket_prefix" optional maxlength="20" mono />
                <Input v-model="form.number_padding" type="number" min="1" max="10" :label="t('support.settings.padding')" :error="form.errors.number_padding" required />
            </div>
            <p class="mt-3 text-sm text-ink-muted">
                {{ t('support.settings.example') }}
                <span class="tabular font-mono text-ink">{{ example }}</span>
            </p>
        </Card>

        <Card :title="t('support.settings.defaults')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.default_priority" :options="priorityOptions" :label="t('support.settings.defaultPriority')" :error="form.errors.default_priority" />
                <Select v-model="form.default_queue_id" :options="queueOptions" :label="t('support.settings.defaultQueue')" :error="form.errors.default_queue_id" />
                <Select v-model="form.default_sla_policy_id" :options="policyOptions" :label="t('support.settings.defaultPolicy')" :hint="t('support.settings.defaultPolicyHint')" :error="form.errors.default_sla_policy_id" />
                <Input v-model="form.auto_close_days" type="number" min="1" max="365" :label="t('support.settings.autoClose')" :hint="t('support.settings.autoCloseHint')" :error="form.errors.auto_close_days" optional />
            </div>
        </Card>

        <Card :title="t('support.settings.csat')" :description="t('support.settings.csatHint')">
            <div class="grid gap-4">
                <Checkbox v-model="form.csat_enabled" :label="t('support.settings.csatEnabled')" :description="t('support.settings.csatEnabledHint')" />
                <Checkbox v-model="form.csat_send_email" :label="t('support.settings.csatEmail')" :description="t('support.settings.csatEmailHint')" />
                <Input v-model="form.csat_expiry_days" class="max-w-xs" type="number" min="1" max="90" :label="t('support.settings.csatExpiry')" :error="form.errors.csat_expiry_days" required />
            </div>
        </Card>

        <div class="flex justify-end">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </div>
    </form>
</template>

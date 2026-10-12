<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { ApprovalFlow, LeaveType } from './types';

const props = defineProps<{ open: boolean; type: LeaveType | null; flows: ApprovalFlow[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({
    name: '',
    code: '',
    is_paid: true,
    tracks_balance: true,
    allows_half_day: true,
    requires_reason: false,
    max_consecutive_days: '',
    min_notice_days: '0',
    approval_flow: 'manager_then_hr' as ApprovalFlow,
    is_active: true,
    sort_order: '0',
});

const flowOptions = computed(() => props.flows.map((value) => ({ value, label: t(`hrm.flow.${value}`) })));

watch(() => props.open, (open) => {
    if (open) {
        const type = props.type;
        form.clearErrors();
        form.name = type?.name ?? '';
        form.code = type?.code ?? '';
        form.is_paid = type?.isPaid ?? true;
        form.tracks_balance = type?.tracksBalance ?? true;
        form.allows_half_day = type?.allowsHalfDay ?? true;
        form.requires_reason = type?.requiresReason ?? false;
        form.max_consecutive_days = type?.maxConsecutiveDays == null ? '' : String(type.maxConsecutiveDays);
        form.min_notice_days = String(type?.minNoticeDays ?? 0);
        form.approval_flow = type?.approvalFlow ?? 'manager_then_hr';
        form.is_active = type?.isActive ?? true;
        form.sort_order = String(type?.sortOrder ?? 0);
    }
}, { immediate: true });

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const transformed = form.transform((data) => ({
        ...data,
        name: data.name.trim(),
        code: data.code.trim(),
        max_consecutive_days: data.max_consecutive_days.trim() === '' ? null : Number(data.max_consecutive_days),
        min_notice_days: Number(data.min_notice_days),
        sort_order: Number(data.sort_order),
    }) as never);

    if (props.type) {
        transformed.put(route('hrm.settings.leave-types.update', { leaveType: props.type.id }), options);
    } else {
        transformed.post(route('hrm.settings.leave-types.store'), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="lg"
        :title="type ? t('hrm.settings.leaveTypes.edit') : t('hrm.settings.leaveTypes.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || form.code.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="100" :error="form.errors.name" required autofocus />
            <Input v-model="form.code" :label="t('hrm.fields.code')" maxlength="20" mono :error="form.errors.code" required />
            <Select v-model="form.approval_flow" :label="t('hrm.fields.approvalFlow')" :options="flowOptions" :error="form.errors.approval_flow" />
            <Input v-model="form.min_notice_days" type="number" min="0" max="365" :label="t('hrm.fields.minNotice')" :error="form.errors.min_notice_days" />
            <Input v-model="form.max_consecutive_days" type="number" min="1" max="366" :label="t('hrm.fields.maxConsecutive')" optional :error="form.errors.max_consecutive_days" />
            <Input v-model="form.sort_order" type="number" min="0" max="10000" :label="t('hrm.fields.sortOrder')" :error="form.errors.sort_order" />
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
            <Checkbox v-model="form.is_paid" :label="t('hrm.settings.leaveTypes.paid')" />
            <Checkbox v-model="form.tracks_balance" :label="t('hrm.settings.leaveTypes.tracksBalance')" />
            <Checkbox v-model="form.allows_half_day" :label="t('hrm.settings.leaveTypes.halfDays')" />
            <Checkbox v-model="form.requires_reason" :label="t('hrm.settings.leaveTypes.requiresReason')" />
            <Checkbox v-model="form.is_active" :label="t('hrm.fields.active')" />
        </div>
    </HrmFormDialog>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { EmployeeStatus, TerminationType } from './types';

/**
 * Moves an employee to another employment status. Suspending or ending
 * employment needs a reason; ending it also needs how and the last working day.
 */
const props = defineProps<{
    open: boolean;
    employeeId: string;
    options: EmployeeStatus[];
    terminationTypes: TerminationType[];
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({
    status: '' as EmployeeStatus | '',
    effective_on: props.today,
    reason: '',
    termination_type: '' as TerminationType | '',
    last_working_on: props.today,
});

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.status = props.options[0] ?? '';
        form.effective_on = props.today;
        form.last_working_on = props.today;
    }
}, { immediate: true });

const statusOptions = computed(() => props.options.map((value) => ({ value, label: t(`hrm.status.employee.${value}`) })));
const typeOptions = computed(() => props.terminationTypes.map((value) => ({ value, label: t(`hrm.termination.${value}`) })));
const terminating = computed(() => form.status === 'terminated');
const needsReason = computed(() => form.status === 'suspended' || terminating.value);
const unusable = computed(() => form.status === ''
    || (needsReason.value && form.reason.trim() === '')
    || (terminating.value && (form.termination_type === '' || form.last_working_on === '')));

function submit(): void {
    form.transform((data) => ({
        status: data.status,
        effective_on: data.effective_on === '' ? null : data.effective_on,
        reason: data.reason.trim() === '' ? null : data.reason.trim(),
        termination_type: data.status === 'terminated' && data.termination_type !== '' ? data.termination_type : null,
        last_working_on: data.status === 'terminated' ? data.last_working_on : null,
    })).post(route('hrm.employees.status', { employee: props.employeeId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.status.change.title')"
        :description="t('hrm.status.change.description')"
        :submit-label="t('hrm.status.change.submit')"
        :danger="terminating"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.status" :label="t('hrm.fields.newStatus')" :options="statusOptions" :error="form.errors.status" />
        <Input v-model="form.effective_on" type="date" :label="t('hrm.fields.effectiveOn')" :error="form.errors.effective_on" required />
        <template v-if="terminating">
            <Select
                v-model="form.termination_type"
                :label="t('hrm.fields.terminationType')"
                :options="typeOptions"
                :placeholder="t('hrm.common.choose')"
                :error="form.errors.termination_type"
            />
            <Input v-model="form.last_working_on" type="date" :label="t('hrm.fields.lastWorkingOn')" :error="form.errors.last_working_on" required />
        </template>
        <Textarea
            v-model="form.reason"
            :label="t('hrm.fields.reason')"
            :optional="!needsReason"
            maxlength="1000"
            :error="form.errors.reason"
        />
    </HrmFormDialog>
</template>

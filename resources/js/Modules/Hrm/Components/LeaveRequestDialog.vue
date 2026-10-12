<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { LeaveSession, LeaveType } from './types';

/** Asks for leave. Half days are offered only where the leave type allows them. */
const props = defineProps<{
    open: boolean;
    types: LeaveType[];
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();

const form = useForm({
    leave_type_id: '',
    starts_on: props.today,
    ends_on: props.today,
    start_session: 'full' as LeaveSession,
    end_session: 'full' as LeaveSession,
    reason: '',
});

const type = computed(() => props.types.find((item) => item.id === form.leave_type_id) ?? null);
const single = computed(() => form.starts_on !== '' && form.starts_on === form.ends_on);
const halfDays = computed(() => type.value?.allowsHalfDay === true);

const typeOptions = computed(() => props.types.map((item) => ({ value: item.id, label: item.name })));

function sessions(values: LeaveSession[]): { value: string; label: string }[] {
    return values.map((value) => ({ value, label: t(`hrm.session.${value}`) }));
}

const dayOptions = computed(() => sessions(['full', 'first_half', 'second_half']));
const startOptions = computed(() => sessions(['full', 'second_half']));
const endOptions = computed(() => sessions(['full', 'first_half']));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.leave_type_id = props.types[0]?.id ?? '';
    }
}, { immediate: true });

// Moving the end before the start is never useful: keep the range ordered.
watch(() => form.starts_on, (value) => {
    if (form.ends_on < value) {
        form.ends_on = value;
    }
});

watch([single, halfDays], () => {
    form.start_session = 'full';
    form.end_session = 'full';
});

const reasonMissing = computed(() => type.value?.requiresReason === true && form.reason.trim() === '');
const unusable = computed(() => form.leave_type_id === '' || form.starts_on === '' || form.ends_on === '' || reasonMissing.value);

function submit(): void {
    form.transform((data) => ({
        ...data,
        end_session: data.starts_on === data.ends_on ? data.start_session : data.end_session,
        reason: data.reason.trim() === '' ? null : data.reason.trim(),
    })).post(route('hrm.leave.requests.store'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="t('hrm.leave.request.title')"
        :description="t('hrm.leave.request.description')"
        :submit-label="t('hrm.leave.request.submit')"
        :processing="form.processing"
        :disabled="unusable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Select v-model="form.leave_type_id" :label="t('hrm.fields.leaveType')" :options="typeOptions" :error="form.errors.leave_type_id" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.starts_on" type="date" :label="t('hrm.fields.from')" :error="form.errors.starts_on" required />
            <Input v-model="form.ends_on" type="date" :label="t('hrm.fields.to')" :min="form.starts_on" :error="form.errors.ends_on" required />
        </div>
        <template v-if="halfDays">
            <Select v-if="single" v-model="form.start_session" :label="t('hrm.fields.duration')" :options="dayOptions" :error="form.errors.start_session" />
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.start_session" :label="t('hrm.fields.firstDay')" :options="startOptions" :error="form.errors.start_session" />
                <Select v-model="form.end_session" :label="t('hrm.fields.lastDay')" :options="endOptions" :error="form.errors.end_session" />
            </div>
        </template>
        <Textarea
            v-model="form.reason"
            :label="t('hrm.fields.reason')"
            :optional="type?.requiresReason !== true"
            maxlength="1000"
            :error="form.errors.reason"
        />
        <p v-if="type && type.minNoticeDays > 0" class="text-xs text-ink-subtle">{{ t('hrm.leave.request.notice', { count: format.number(type.minNoticeDays) }) }}</p>
    </HrmFormDialog>
</template>

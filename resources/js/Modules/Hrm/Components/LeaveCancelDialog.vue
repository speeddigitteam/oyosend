<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { LeaveRequest } from './types';

/** Cancels a leave request; the units are returned to the balance. */
const props = defineProps<{ open: boolean; request: LeaveRequest | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ reason: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    if (props.request === null) {
        return;
    }

    form.transform((data) => ({ reason: data.reason.trim() === '' ? null : data.reason.trim() }))
        .post(route('hrm.leave.requests.cancel', { leaveRequest: props.request.id }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="sm"
        :title="t('hrm.leave.cancel.title')"
        :description="t('hrm.leave.cancel.description')"
        :submit-label="t('hrm.leave.cancel.submit')"
        danger
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Textarea v-model="form.reason" :label="t('hrm.fields.reason')" optional maxlength="1000" :error="form.errors.reason" />
    </HrmFormDialog>
</template>

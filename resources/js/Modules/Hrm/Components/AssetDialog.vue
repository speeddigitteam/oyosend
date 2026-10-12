<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { EmployeeAsset } from './types';

/**
 * Hands an asset to an employee, or (with `returning`) takes one back. An asset
 * tag can be held by one person at a time.
 */
const props = defineProps<{
    open: boolean;
    employeeId: string;
    today: string;
    returning?: EmployeeAsset | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

/** Server errors for fields the form sends under another name. */
const serverErrors = computed(() => form.errors as Record<string, string | undefined>);
const form = useForm({ name: '', asset_tag: '', category: '', date: props.today, condition: '', notes: '' });
const isReturn = computed(() => props.returning != null);

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.date = props.today;
    }
}, { immediate: true });

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const notes = form.notes.trim() === '' ? null : form.notes.trim();

    if (props.returning) {
        form.transform((data) => ({
            returned_on: data.date === '' ? null : data.date,
            return_condition: data.condition.trim() === '' ? null : data.condition.trim(),
            notes,
        })).post(route('hrm.employees.assets.return', { employee: props.employeeId, asset: props.returning.id }), options);

        return;
    }

    form.transform((data) => ({
        name: data.name.trim(),
        asset_tag: data.asset_tag.trim() === '' ? null : data.asset_tag.trim(),
        category: data.category.trim() === '' ? null : data.category.trim(),
        assigned_on: data.date === '' ? null : data.date,
        notes,
    })).post(route('hrm.employees.assets.store', { employee: props.employeeId }), options);
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        :title="isReturn ? t('hrm.assets.returnTitle', { name: returning?.name ?? '' }) : t('hrm.assets.assign')"
        :submit-label="isReturn ? t('hrm.assets.return') : t('hrm.assets.assignSubmit')"
        :processing="form.processing"
        :disabled="!isReturn && form.name.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <template v-if="!isReturn">
            <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="160" :error="form.errors.name" required autofocus />
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.asset_tag" :label="t('hrm.assets.tag')" optional maxlength="60" mono :error="form.errors.asset_tag" />
                <Input v-model="form.category" :label="t('hrm.fields.category')" optional maxlength="60" :error="form.errors.category" />
            </div>
        </template>
        <Input v-model="form.date" type="date" :label="isReturn ? t('hrm.assets.returnedOn') : t('hrm.assets.assignedOn')" :error="serverErrors.returned_on ?? serverErrors.assigned_on" />
        <Input v-if="isReturn" v-model="form.condition" :label="t('hrm.assets.condition')" optional maxlength="60" :error="serverErrors.return_condition" />
        <Textarea v-model="form.notes" :label="t('hrm.fields.notes')" optional maxlength="2000" :error="form.errors.notes" />
    </HrmFormDialog>
</template>

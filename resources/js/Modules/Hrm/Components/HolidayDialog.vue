<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import HrmFormDialog from './HrmFormDialog.vue';
import type { Holiday, HolidayType } from './types';

const props = defineProps<{
    open: boolean;
    holiday: Holiday | null;
    /** The year being edited, used to start a new holiday inside it. */
    year: number;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', holiday_on: '', type: 'public' as HolidayType });
const types = computed(() => (['public', 'optional'] as const).map((value) => ({ value, label: t(`hrm.status.holiday.${value}`) })));

watch(() => props.open, (open) => {
    if (open) {
        form.clearErrors();
        form.name = props.holiday?.name ?? '';
        form.holiday_on = props.holiday?.date ?? `${props.year}-01-01`;
        form.type = props.holiday?.type ?? 'public';
    }
}, { immediate: true });

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.holiday) {
        form.put(route('hrm.holidays.update', { holiday: props.holiday.id }), options);
    } else {
        form.post(route('hrm.holidays.store'), options);
    }
}
</script>

<template>
    <HrmFormDialog
        :open="open"
        size="sm"
        :title="holiday ? t('hrm.holidays.edit') : t('hrm.holidays.add')"
        :submit-label="t('hrm.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || form.holiday_on === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('hrm.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Input v-model="form.holiday_on" type="date" :label="t('hrm.fields.date')" :error="form.errors.holiday_on" required />
        <Select v-model="form.type" :label="t('hrm.fields.type')" :options="types" :error="form.errors.type" />
    </HrmFormDialog>
</template>

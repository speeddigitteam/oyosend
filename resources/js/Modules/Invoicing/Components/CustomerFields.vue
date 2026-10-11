<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from '@/Core/Components/Select.vue';
import type { Option } from './types';

const props = defineProps<{
    companies: Option[];
    contacts: Option[];
    companyError?: string;
    contactError?: string;
}>();

const companyId = defineModel<string>('companyId', { required: true });
const contactId = defineModel<string>('contactId', { required: true });
const { t } = useI18n();

const options = (items: Option[]) => [
    { value: '', label: t('invoicing.common.notSet') },
    ...items.map((item) => ({ value: item.id, label: item.name })),
];
const companyOptions = computed(() => options(props.companies));
const contactOptions = computed(() => options(props.contacts));
</script>

<template>
    <Select v-model="companyId" :options="companyOptions" :label="t('invoicing.fields.company')" :error="companyError" />
    <Select v-model="contactId" :options="contactOptions" :label="t('invoicing.fields.contact')" :error="contactError" :hint="t('invoicing.form.customerHint')" />
</template>

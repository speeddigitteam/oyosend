<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Deal, Option, Pipeline } from './types';

const props = withDefaults(defineProps<{ deal?: Deal | null; pipelines: Pipeline[]; leads?: Option[]; contacts?: Option[]; companies?: Option[]; owners?: Option[]; initialContactId?: string | null }>(), {
    deal: null, leads: () => [], contacts: () => [], companies: () => [], owners: () => [], initialContactId: null,
});
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const page = usePage();
const form = useForm({
    title: '', pipeline_id: '', stage_id: '', lead_id: '', contact_id: '', company_id: '',
    currency: page.props.app.currency, amount: 0, expected_close_date: '', description: '' as string | null, owner_id: '',
});

const selectedPipeline = computed(() => props.pipelines.find((item) => item.id === form.pipeline_id) ?? null);
const stageOptions = computed(() => (selectedPipeline.value?.stages ?? []).map((stage) => ({ value: stage.id, label: stage.name })));
const optionList = (items: Option[]) => [{ value: '', label: t('crm.common.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];

function fill(): void {
    const pipeline = props.deal?.pipeline.id ?? props.pipelines[0]?.id ?? '';
    form.clearErrors();
    form.title = props.deal?.title ?? '';
    form.pipeline_id = pipeline;
    form.stage_id = props.deal?.stage.id ?? props.pipelines.find((item) => item.id === pipeline)?.stages[0]?.id ?? '';
    form.lead_id = props.deal?.lead?.id ?? '';
    form.contact_id = props.deal ? (props.deal.contact?.id ?? '') : (props.initialContactId ?? '');
    form.company_id = props.deal?.company?.id ?? '';
    form.currency = props.deal?.currency ?? page.props.app.currency;
    form.amount = props.deal?.amount ?? 0;
    form.expected_close_date = props.deal?.expectedCloseDate ?? '';
    form.description = props.deal?.description ?? '';
    form.owner_id = props.deal?.owner?.id ?? '';
}

watch(open, (shown) => shown && fill());
watch(() => form.pipeline_id, () => {
    if (!stageOptions.value.some((stage) => stage.value === form.stage_id)) form.stage_id = stageOptions.value[0]?.value ?? '';
});

function submit(): void {
    const settings = { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } };
    if (props.deal) form.put(route('crm.deals.update', { deal: props.deal.id }), settings);
    else form.post(route('crm.deals.store'), settings);
}
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="t(deal ? 'crm.deals.editTitle' : 'crm.deals.createTitle')" :persistent="form.processing">
        <form id="crm-deal-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.title" class="sm:col-span-2" :label="t('crm.fields.title')" :error="form.errors.title" required maxlength="200" autofocus />
            <Select v-if="!deal" v-model="form.pipeline_id" :options="pipelines.map((item) => ({ value: item.id, label: item.name }))" :label="t('crm.fields.pipeline')" :error="form.errors.pipeline_id" required />
            <Select v-if="!deal" v-model="form.stage_id" :options="stageOptions" :label="t('crm.fields.stage')" :error="form.errors.stage_id" required />
            <Select v-model="form.lead_id" :options="optionList(leads)" :label="t('crm.nav.leads')" :error="form.errors.lead_id" />
            <Select v-model="form.contact_id" :options="optionList(contacts)" :label="t('crm.fields.contact')" :error="form.errors.contact_id" />
            <Select v-model="form.company_id" :options="optionList(companies)" :label="t('crm.fields.company')" :error="form.errors.company_id" />
            <Select v-if="owners.length" v-model="form.owner_id" :options="optionList(owners)" :label="t('crm.fields.owner')" :error="form.errors.owner_id" />
            <Input v-model="form.amount" type="number" min="0" max="1000000000000000" step="1" :label="t('crm.fields.amount')" :error="form.errors.amount" required />
            <Input v-model="form.currency" maxlength="3" :label="t('crm.fields.currency')" :error="form.errors.currency" required />
            <Input v-model="form.expected_close_date" type="date" :label="t('crm.fields.expectedClose')" :error="form.errors.expected_close_date" optional />
            <Textarea v-model="form.description" class="sm:col-span-2" :label="t('crm.fields.description')" :error="form.errors.description" optional />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('crm.common.cancel') }}</Button>
            <Button form="crm-deal-form" type="submit" variant="primary" :loading="form.processing">{{ t('crm.common.save') }}</Button>
        </template>
    </Modal>
</template>

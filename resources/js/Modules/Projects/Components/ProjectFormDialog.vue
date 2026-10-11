<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Project, ProjectFormOptions, TaskPriority } from './types';
const props = withDefaults(defineProps<{ open: boolean; options: ProjectFormOptions; project?: Project | null; canManageFinancials?: boolean }>(), { project: null, canManageFinancials: false });
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });
const form = useForm({ name: '', description: '', template_id: '', category_id: '', owner_id: '', contact_id: '', company_id: '', priority: 'normal' as TaskPriority, starts_on: '', due_on: '', budget_amount: '' });
const priorities: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];
const optional = (items: { id: string; name: string }[]) => [{ value: '', label: t('projects.common.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];
watch(() => props.open, (open) => {
    if (!open) return;
    const project = props.project;
    form.defaults({ name: project?.name ?? '', description: project?.description ?? '', template_id: '', category_id: project?.category?.id ?? '', owner_id: project?.owner?.id ?? '', contact_id: project?.contact?.id ?? '', company_id: project?.company?.id ?? '', priority: project?.priority ?? 'normal', starts_on: project?.startsOn ?? '', due_on: project?.dueOn ?? '', budget_amount: project?.budgetAmount?.toString() ?? '0' });
    form.reset(); form.clearErrors();
}, { immediate: true });
function submit(): void {
    form.transform((data) => {
        const { owner_id: _owner, budget_amount: _budget, ...base } = data;
        return {
            ...base,
            ...(props.project ? {} : { owner_id: data.owner_id }),
            ...(props.canManageFinancials ? { budget_amount: data.budget_amount } : {}),
        };
    });
    const settings = { preserveScroll: true, onSuccess: () => { model.value = false; } };
    if (props.project) form.put(route('projects.update', { project: props.project.id }), settings);
    else form.post(route('projects.store'), settings);
}
</script>
<template>
    <Modal v-model:open="model" size="lg" :title="t(project ? 'projects.projects.edit' : 'projects.projects.create')" :persistent="form.processing">
        <form id="project-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <Input v-model="form.name" class="sm:col-span-2" :label="t('projects.fields.name')" :error="form.errors.name" required maxlength="200" autofocus />
            <Select v-if="!project && options.templates.length" v-model="form.template_id" :options="optional(options.templates)" :label="t('projects.fields.template')" :error="form.errors.template_id" />
            <Select v-model="form.priority" :options="priorities.map((value) => ({ value, label: t(`projects.status.${value}`) }))" :label="t('projects.fields.priority')" :error="form.errors.priority" required />
            <Select v-model="form.category_id" :options="optional(options.categories)" :label="t('projects.fields.category')" :error="form.errors.category_id" />
            <Select v-if="!project" v-model="form.owner_id" :options="optional(options.owners)" :label="t('projects.fields.owner')" :error="form.errors.owner_id" />
            <Select v-model="form.contact_id" :options="optional(options.contacts)" :label="t('projects.fields.contact')" :error="form.errors.contact_id" />
            <Select v-model="form.company_id" :options="optional(options.companies)" :label="t('projects.fields.company')" :error="form.errors.company_id" />
            <Input v-model="form.starts_on" type="date" :label="t('projects.fields.startDate')" :error="form.errors.starts_on" />
            <Input v-model="form.due_on" type="date" :label="t('projects.fields.dueDate')" :error="form.errors.due_on" />
            <Input v-if="canManageFinancials" v-model="form.budget_amount" type="number" min="0" max="1000000000000000" step="1" :label="t('projects.fields.budgetWithCurrency', { currency: options.currency })" :error="form.errors.budget_amount" required />
            <Textarea v-model="form.description" class="sm:col-span-2" :label="t('projects.fields.description')" :error="form.errors.description" optional maxlength="20000" />
        </form>
        <template #footer><Button variant="ghost" :disabled="form.processing" @click="model = false">{{ t('projects.common.cancel') }}</Button><Button form="project-form" type="submit" variant="primary" :loading="form.processing">{{ t('projects.common.save') }}</Button></template>
    </Modal>
</template>


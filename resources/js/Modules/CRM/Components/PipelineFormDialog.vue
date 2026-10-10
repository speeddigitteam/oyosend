<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { route } from '@/Core/lib/route';

const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const blank = () => ({ name: '', probability: 0, is_won: false, is_lost: false });
const form = useForm({ name: '', default: false, stages: [blank()] });
function addStage(): void { form.stages.push(blank()); }
function removeStage(index: number): void { if (form.stages.length > 1) form.stages.splice(index, 1); }
function submit(): void { form.post(route('crm.settings.pipelines.store'), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } }); }
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="t('crm.settings.addPipeline')" :persistent="form.processing">
        <form id="crm-pipeline-form" class="grid gap-5" @submit.prevent="submit">
            <Input v-model="form.name" :label="t('crm.fields.name')" :error="form.errors.name" required maxlength="120" autofocus />
            <Checkbox v-model="form.default" :label="t('crm.settings.makeDefault')" />
            <fieldset class="grid gap-3"><legend class="mb-1 text-sm font-semibold text-ink">{{ t('crm.settings.stages') }}</legend><div v-for="(stage, index) in form.stages" :key="index" class="glass-well grid gap-3 rounded-control p-3 sm:grid-cols-[1fr_8rem_auto]"><Input v-model="stage.name" :label="t('crm.settings.stageName')" :error="form.errors[`stages.${index}.name`]" required maxlength="100" /><Input v-model="stage.probability" type="number" min="0" max="100" :label="t('crm.fields.probability')" :error="form.errors[`stages.${index}.probability`]" required /><Button icon size="sm" variant="ghost" class="self-end" :disabled="form.stages.length === 1" :aria-label="t('crm.settings.removeStage')" @click="removeStage(index)"><template #icon><Trash2 class="size-4" aria-hidden="true" /></template></Button><div class="flex flex-wrap gap-4 sm:col-span-3"><Checkbox v-model="stage.is_won" :label="t('crm.settings.wonStage')" /><Checkbox v-model="stage.is_lost" :label="t('crm.settings.lostStage')" /></div></div><Button size="sm" variant="ghost" class="justify-self-start" @click="addStage"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.settings.addStage') }}</Button></fieldset>
        </form>
        <template #footer><Button variant="ghost" @click="open = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-pipeline-form" type="submit" variant="primary" :loading="form.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>
</template>

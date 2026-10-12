<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import { parseInteger } from './talentFormat';
import type { OpeningForm, OpeningRow } from './types';

/**
 * The fields of a job opening, for the create page and the edit dialog.
 * A new opening starts as a draft or opens at once (which counts against the
 * plan limit); status changes after that go through the status menu.
 */
const props = defineProps<{ options: OpeningForm; opening: OpeningRow | null }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const { t } = useI18n();

const form = useForm({
    title: '',
    department_id: '',
    branch_id: '',
    hiring_manager_id: '',
    pipeline_id: '',
    employment_type: '',
    location: '',
    headcount: '1',
    description: '',
    requirements: '',
    opens_on: '',
    closes_on: '',
    status: 'draft',
});

watch(() => props.opening, (opening) => {
    form.defaults({
        title: opening?.title ?? '',
        department_id: opening?.department?.id ?? '',
        branch_id: opening?.branch?.id ?? '',
        hiring_manager_id: opening?.hiringManager?.id ?? '',
        pipeline_id: opening?.pipelineId ?? props.options.defaultPipelineId,
        employment_type: opening?.employmentType ?? '',
        location: opening?.location ?? '',
        headcount: String(opening?.headcount ?? 1),
        description: opening?.description ?? '',
        requirements: opening?.requirements ?? '',
        opens_on: opening?.opensOn ?? '',
        closes_on: opening?.closesOn ?? '',
        status: 'draft',
    });
    form.reset();
}, { immediate: true });

const withNone = (items: { value: string; label: string }[]) => [{ value: '', label: t('talent.common.none') }, ...items];

const departments = computed(() => withNone(props.options.departments.map((item) => ({ value: item.id, label: item.name }))));
const branches = computed(() => withNone(props.options.branches.map((item) => ({ value: item.id, label: item.name }))));
const managers = computed(() => withNone(props.options.hiringManagers.map((item) => ({ value: item.id, label: item.name }))));
const pipelines = computed(() => props.options.pipelines.map((item) => ({ value: item.id, label: item.name })));
const types = computed(() => withNone(props.options.employmentTypes.map((value) => ({ value, label: t(`talent.status.employmentType.${value}`) }))));
const statuses = computed(() => [
    { value: 'draft', label: t('talent.status.opening.draft') },
    { value: 'open', label: t('talent.openings.publishNow') },
]);
const headcount = computed(() => parseInteger(form.headcount));
const headcountInvalid = computed(() => headcount.value === null || headcount.value < 1 || headcount.value > 500);

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => ({
        title: data.title.trim(),
        department_id: blank(data.department_id),
        branch_id: blank(data.branch_id),
        hiring_manager_id: blank(data.hiring_manager_id),
        pipeline_id: props.options.canChangePipeline ? blank(data.pipeline_id) : null,
        employment_type: blank(data.employment_type),
        location: blank(data.location),
        headcount: headcount.value ?? 1,
        description: blank(data.description),
        requirements: blank(data.requirements),
        opens_on: blank(data.opens_on),
        closes_on: blank(data.closes_on),
        status: props.opening ? null : data.status,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('saved') };

    if (props.opening) {
        form.put(route('talent.openings.update', { opening: props.opening.id }), options);
    } else {
        form.post(route('talent.openings.store'), options);
    }
}
</script>

<template>
    <form class="grid gap-4" novalidate @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.title" class="sm:col-span-2" :label="t('talent.fields.title')" maxlength="160" :error="form.errors.title" required autofocus />
            <Select v-model="form.department_id" :options="departments" :label="t('talent.fields.department')" :error="form.errors.department_id" />
            <Select v-model="form.branch_id" :options="branches" :label="t('talent.fields.branch')" :error="form.errors.branch_id" />
            <Select v-model="form.hiring_manager_id" :options="managers" :label="t('talent.fields.hiringManager')" :error="form.errors.hiring_manager_id" />
            <Select v-model="form.employment_type" :options="types" :label="t('talent.fields.employmentType')" :error="form.errors.employment_type" />
            <Input v-model="form.location" :label="t('talent.fields.location')" maxlength="160" optional :error="form.errors.location" />
            <Input
                v-model="form.headcount"
                type="number"
                min="1"
                max="500"
                :label="t('talent.openings.headcount')"
                :error="form.errors.headcount ?? (headcountInvalid ? t('talent.openings.headcountInvalid') : undefined)"
                required
            />
            <Select
                v-if="options.canChangePipeline"
                v-model="form.pipeline_id"
                :options="pipelines"
                :label="t('talent.fields.pipeline')"
                :error="form.errors.pipeline_id"
            />
            <Input v-model="form.opens_on" type="date" :label="t('talent.openings.opensOn')" optional :error="form.errors.opens_on" />
            <Input v-model="form.closes_on" type="date" :label="t('talent.openings.closesOn')" optional :error="form.errors.closes_on" />
            <Select v-if="!opening" v-model="form.status" :options="statuses" :label="t('talent.fields.status')" :error="form.errors.status" />
        </div>
        <Textarea v-model="form.description" :label="t('talent.openings.descriptionLabel')" maxlength="10000" optional :rows="4" :error="form.errors.description" />
        <Textarea v-model="form.requirements" :label="t('talent.openings.requirements')" maxlength="10000" optional :rows="4" :error="form.errors.requirements" />
        <div class="flex justify-end gap-2">
            <Button type="button" variant="ghost" :disabled="form.processing" @click="emit('cancel')">{{ t('talent.common.cancel') }}</Button>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="form.title.trim() === '' || headcountInvalid">
                {{ opening ? t('talent.common.save') : t('talent.openings.create') }}
            </Button>
        </div>
    </form>
</template>

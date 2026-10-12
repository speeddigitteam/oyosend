<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BlockEditor from '../../Components/BlockEditor.vue';
import EmailPreview from '../../Components/EmailPreview.vue';
import { newBlock } from '../../Components/emailFormat';
import type { Block, CampaignDetail, CampaignFormOptions, TemplateRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaign: CampaignDetail | null;
    form: CampaignFormOptions;
    template: TemplateRow | null;
    canSend: boolean;
}>();

const { t } = useI18n();

const fromTemplate = props.template;
const initialBlocks: Block[] = props.campaign?.blocks ?? fromTemplate?.blocks ?? [newBlock('heading'), newBlock('text')];

const form = useForm({
    name: props.campaign?.name ?? fromTemplate?.name ?? '',
    subject: props.campaign?.subject ?? fromTemplate?.subject ?? '',
    preheader: props.campaign?.preheader ?? fromTemplate?.preheader ?? '',
    list_id: props.campaign?.listId ?? props.form.lists[0]?.id ?? '',
    segment_id: props.campaign?.segmentId ?? '',
    template_id: props.campaign?.templateId ?? fromTemplate?.id ?? '',
    track_clicks: props.campaign?.trackClicks ?? true,
    ab_enabled: props.campaign?.ab.enabled ?? false,
    ab_subject_b: props.campaign?.ab.subjectB ?? '',
    ab_test_percent: String(props.campaign?.ab.percent ?? 20),
    ab_wait_hours: String(props.campaign?.ab.waitHours ?? 4),
    ab_metric: props.campaign?.ab.metric ?? 'clicks',
    blocks: initialBlocks,
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const listOptions = computed(() => props.form.lists.map((item) => ({ value: item.id, label: item.name })));
const segmentOptions = computed(() => {
    const list = props.form.lists.find((item) => item.id === form.list_id);

    return [
        { value: '', label: t('emailMarketing.campaigns.wholeList') },
        ...(list?.segments ?? []).map((segment) => ({ value: segment.id, label: segment.name })),
    ];
});
const templateOptions = computed(() => [
    { value: '', label: t('emailMarketing.campaigns.noTemplate') },
    ...props.form.templates.map((item) => ({ value: item.id, label: item.name })),
]);

// A segment belongs to one list: changing the list clears it.
watch(() => form.list_id, () => {
    if (!segmentOptions.value.some((option) => option.value === form.segment_id)) {
        form.segment_id = '';
    }
});

const invalid = computed(() => form.name.trim() === '' || form.subject.trim() === '' || form.list_id === '' || form.blocks.length === 0);
const abInvalid = computed(() => form.ab_enabled && (form.ab_subject_b.trim() === '' || form.ab_subject_b.trim() === form.subject.trim()));

function save(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        subject: data.subject.trim(),
        preheader: data.preheader.trim() === '' ? null : data.preheader.trim(),
        list_id: data.list_id,
        segment_id: data.segment_id === '' ? null : data.segment_id,
        template_id: data.template_id === '' ? null : data.template_id,
        track_clicks: data.track_clicks,
        ab_enabled: data.ab_enabled,
        ab_subject_b: data.ab_enabled ? data.ab_subject_b.trim() : null,
        ab_test_percent: data.ab_enabled ? Number(data.ab_test_percent) : null,
        ab_wait_hours: data.ab_enabled ? Number(data.ab_wait_hours) : null,
        ab_metric: data.ab_enabled ? data.ab_metric : null,
        blocks: data.blocks,
    }));

    if (props.campaign) {
        form.put(route('email_marketing.campaigns.update', { campaign: props.campaign.id }), { preserveScroll: true });
    } else {
        form.post(route('email_marketing.campaigns.store'), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('emailMarketing.eyebrow')"
        :title="campaign ? t('emailMarketing.campaigns.edit') : t('emailMarketing.campaigns.add')"
        :description="t('emailMarketing.campaigns.formDescription')"
    />

    <form class="grid gap-3 lg:grid-cols-3" novalidate @submit.prevent="save">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card :title="t('emailMarketing.campaigns.details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.name" :label="t('emailMarketing.fields.name')" maxlength="150" required :error="errors.name" />
                    <Input v-model="form.subject" :label="t('emailMarketing.fields.subject')" maxlength="200" required :error="errors.subject" />
                    <Input
                        v-model="form.preheader"
                        class="sm:col-span-2"
                        :label="t('emailMarketing.fields.preheader')"
                        :hint="t('emailMarketing.fields.preheaderHint')"
                        maxlength="200"
                        optional
                        :error="errors.preheader"
                    />
                    <Select v-model="form.list_id" :options="listOptions" :label="t('emailMarketing.fields.list')" :error="errors.list_id" />
                    <Select v-model="form.segment_id" :options="segmentOptions" :label="t('emailMarketing.fields.segment')" :error="errors.segment_id" />
                    <Select
                        v-if="props.form.templates.length > 0"
                        v-model="form.template_id"
                        :options="templateOptions"
                        :label="t('emailMarketing.fields.template')"
                        :hint="t('emailMarketing.campaigns.templateHint')"
                    />
                </div>
                <p v-if="errors.campaign" class="mt-3 text-sm text-danger" role="alert">{{ errors.campaign }}</p>
            </Card>

            <Card :title="t('emailMarketing.campaigns.content')" :description="t('emailMarketing.campaigns.contentDescription')">
                <BlockEditor
                    v-model="form.blocks"
                    :variables="props.form.editor.variables"
                    :errors="errors"
                    :max="props.form.editor.maxBlocks"
                    :max-text="props.form.editor.maxText"
                />
            </Card>

            <Card :title="t('emailMarketing.campaigns.options')">
                <div class="grid gap-4">
                    <Checkbox
                        v-model="form.track_clicks"
                        :label="t('emailMarketing.campaigns.trackClicks')"
                        :description="t('emailMarketing.campaigns.trackClicksHint')"
                    />
                    <Checkbox
                        v-model="form.ab_enabled"
                        :label="t('emailMarketing.campaigns.abEnabled')"
                        :description="t('emailMarketing.campaigns.abHint')"
                    />
                    <div v-if="form.ab_enabled" class="grid gap-4 sm:grid-cols-2">
                        <Input
                            v-model="form.ab_subject_b"
                            class="sm:col-span-2"
                            :label="t('emailMarketing.campaigns.abSubjectB')"
                            maxlength="200"
                            :error="errors.ab_subject_b ?? (abInvalid && form.ab_subject_b.trim() !== '' ? t('emailMarketing.campaigns.abSame') : undefined)"
                        />
                        <Input
                            v-model="form.ab_test_percent"
                            inputmode="numeric"
                            :label="t('emailMarketing.campaigns.abPercent')"
                            :hint="t('emailMarketing.campaigns.abPercentHint')"
                            :error="errors.ab_test_percent"
                        />
                        <Input
                            v-model="form.ab_wait_hours"
                            inputmode="numeric"
                            :label="t('emailMarketing.campaigns.abWait')"
                            :hint="t('emailMarketing.campaigns.abWaitHint')"
                            :error="errors.ab_wait_hours"
                        />
                        <Select
                            v-model="form.ab_metric"
                            :label="t('emailMarketing.campaigns.abMetric')"
                            :options="[
                                { value: 'clicks', label: t('emailMarketing.campaigns.abMetricClicks') },
                                { value: 'views', label: t('emailMarketing.campaigns.abMetricViews') },
                            ]"
                            :error="errors.ab_metric"
                        />
                    </div>
                </div>
            </Card>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid || abInvalid">
                    {{ t('emailMarketing.common.save') }}
                </Button>
                <Button variant="ghost" :href="campaign ? route('email_marketing.campaigns.show', { campaign: campaign.id }) : route('email_marketing.campaigns.index')">
                    {{ t('emailMarketing.common.cancel') }}
                </Button>
            </div>
        </div>

        <Card :title="t('emailMarketing.preview.title')" class="self-start lg:sticky lg:top-4">
            <EmailPreview :blocks="form.blocks" :subject="form.subject" :preheader="form.preheader" />
        </Card>
    </form>
</template>

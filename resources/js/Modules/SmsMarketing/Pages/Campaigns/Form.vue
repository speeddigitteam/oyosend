<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { sendJson } from '@/Modules/Automation/lib/http';
import ChannelNotice from '../../Components/ChannelNotice.vue';
import SegmentMeter from '../../Components/SegmentMeter.vue';
import { countSegments, unknownVariables } from '../../Components/smsFormat';
import type { AudienceEstimate, CampaignDetail, FormOptions, SegmentCount } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaign: CampaignDetail | null;
    form: FormOptions;
    canEstimate: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const MARKETING_VARIABLES = ['first_name', 'last_name', 'full_name', 'company_name'];

const draft = useForm({
    name: props.campaign?.name ?? '',
    template_id: props.campaign?.templateId ?? '',
    body: props.campaign?.body ?? '',
    sender_identity_id: props.campaign?.senderIdentityId ?? props.form.defaultSenderId ?? '',
    group_ids: [...(props.campaign?.groupIds ?? [])] as string[],
});

const groupError = computed(() => draft.errors.group_ids ?? (draft.errors as Record<string, string | undefined>).groups);
const estimating = ref(false);
const estimate = ref<AudienceEstimate | null>(null);
const estimateError = ref<string | null>(null);

const templateOptions = computed(() => [
    { value: '', label: t('smsMarketing.form.noTemplate') },
    ...props.form.templates.map((template) => ({ value: template.id, label: template.name })),
]);
const senderOptions = computed(() => [
    { value: '', label: t('smsMarketing.form.noSender') },
    ...props.form.senders.map((sender) => ({ value: sender.id, label: `${sender.label} (${sender.value})` })),
]);

// The live count includes the opt-out line: its link takes about as many characters as a real one.
const SAMPLE_LINK = `https://workspace.example/communication/unsubscribe/${'x'.repeat(40)}`;
const count = computed<SegmentCount>(() => countSegments(`${draft.body}\n${props.form.optOutWording} ${SAMPLE_LINK}`));
const unknown = computed(() => unknownVariables(draft.body, MARKETING_VARIABLES));
const tooLong = computed(() => count.value.segments > props.form.limits.maxSegments);
const invalid = computed(
    () => draft.name.trim() === '' || draft.body.trim() === '' || draft.group_ids.length === 0 || unknown.value.length > 0 || tooLong.value,
);

watch(
    () => draft.template_id,
    (id) => {
        const template = props.form.templates.find((item) => item.id === id);

        if (template && draft.body.trim() === '') {
            draft.body = template.body;
        }
    },
);

function toggleGroup(id: string, on: boolean): void {
    draft.group_ids = on ? [...new Set([...draft.group_ids, id])] : draft.group_ids.filter((item) => item !== id);
}

async function runEstimate(): Promise<void> {
    estimating.value = true;
    estimateError.value = null;

    const response = await sendJson<{ audience: AudienceEstimate }>('POST', route('sms_marketing.campaigns.estimate'), {
        body: draft.body,
        group_ids: draft.group_ids,
    });

    estimating.value = false;

    if (response.ok && response.data) {
        estimate.value = response.data.audience;

        return;
    }

    estimate.value = null;
    estimateError.value = response.message ?? t('smsMarketing.form.estimateFailed');
}

function submit(): void {
    draft.transform((data) => ({
        ...data,
        name: data.name.trim(),
        template_id: data.template_id === '' ? null : data.template_id,
        sender_identity_id: data.sender_identity_id === '' ? null : data.sender_identity_id,
    }));

    if (props.campaign) {
        draft.put(route('sms_marketing.campaigns.update', { campaign: props.campaign.id }), { preserveScroll: true });
    } else {
        draft.post(route('sms_marketing.campaigns.store'), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('smsMarketing.eyebrow')"
        :title="campaign ? t('smsMarketing.form.editTitle') : t('smsMarketing.form.createTitle')"
        :description="t('smsMarketing.form.description')"
    />

    <ChannelNotice class="mb-3" :channel="form.channel" />

    <form class="grid gap-3 lg:grid-cols-3" novalidate @submit.prevent="submit">
        <div class="grid gap-3 lg:col-span-2">
            <Card :title="t('smsMarketing.form.message')">
                <div class="grid gap-4">
                    <Input v-model="draft.name" :label="t('smsMarketing.fields.name')" maxlength="120" :error="draft.errors.name" required autofocus />
                    <Select
                        v-model="draft.template_id"
                        :options="templateOptions"
                        :label="t('smsMarketing.fields.template')"
                        :hint="t('smsMarketing.form.templateHint')"
                        :error="draft.errors.template_id"
                    />
                    <Textarea
                        v-model="draft.body"
                        :label="t('smsMarketing.fields.body')"
                        :rows="5"
                        :maxlength="form.limits.maxBody"
                        :hint="t('smsMarketing.form.bodyHint', { first: '{{first_name}}', fallback: '{{first_name|friend}}' })"
                        :error="draft.errors.body ?? (unknown.length > 0 ? t('smsMarketing.form.unknownVariables', { names: unknown.join(', ') }) : undefined)"
                        required
                    />
                    <SegmentMeter :count="count" :max-segments="form.limits.maxSegments" />
                    <p class="text-[0.8125rem] text-ink-muted" data-testid="optout-note">
                        {{ t('smsMarketing.form.optOutNote', { wording: form.optOutWording }) }}
                    </p>
                    <Select
                        v-model="draft.sender_identity_id"
                        :options="senderOptions"
                        :label="t('smsMarketing.fields.sender')"
                        :hint="t('smsMarketing.form.senderHint')"
                        :error="draft.errors.sender_identity_id"
                    />
                </div>
            </Card>

            <Card :title="t('smsMarketing.form.audience')" :description="t('smsMarketing.form.audienceHint')">
                <p v-if="form.groups.length === 0" class="text-[0.8125rem] text-ink-muted">{{ t('smsMarketing.form.noGroups') }}</p>
                <ul v-else class="grid gap-2 sm:grid-cols-2">
                    <li v-for="group in form.groups" :key="group.id">
                        <Checkbox
                            :model-value="draft.group_ids.includes(group.id)"
                            :label="group.name"
                            :description="t('smsMarketing.groups.members', { count: format.number(group.membersCount) })"
                            @update:model-value="toggleGroup(group.id, $event)"
                        />
                    </li>
                </ul>
                <p v-if="groupError" class="mt-2 text-[0.8125rem] text-danger">
                    {{ groupError }}
                </p>
            </Card>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('smsMarketing.form.estimate')" :description="t('smsMarketing.form.estimateHint')">
                <div class="grid gap-3">
                    <Button
                        v-if="canEstimate"
                        type="button"
                        :loading="estimating"
                        :disabled="draft.body.trim() === '' || draft.group_ids.length === 0 || unknown.length > 0"
                        @click="runEstimate"
                    >
                        {{ t('smsMarketing.form.estimateAction') }}
                    </Button>
                    <p v-if="estimateError" class="text-[0.8125rem] text-danger" role="alert">{{ estimateError }}</p>
                    <dl v-if="estimate" class="grid gap-2 text-[0.8125rem]" data-testid="estimate">
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('smsMarketing.estimate.contacts') }}</dt>
                            <dd class="tabular text-ink">{{ format.number(estimate.contacts) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('smsMarketing.estimate.validNumbers') }}</dt>
                            <dd class="tabular text-ink">{{ format.number(estimate.validNumbers) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('smsMarketing.estimate.sendable') }}</dt>
                            <dd class="tabular text-ink">
                                {{ estimate.sendable === null ? t('smsMarketing.estimate.notChecked') : format.number(estimate.sendable) }}
                            </dd>
                        </div>
                        <div v-for="(total, reason) in estimate.blocked" :key="reason" class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t(`smsMarketing.reasons.${reason}`) }}</dt>
                            <dd class="tabular text-ink">{{ format.number(total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('smsMarketing.estimate.segments') }}</dt>
                            <dd class="tabular text-ink">{{ format.number(estimate.segments) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('smsMarketing.estimate.cost') }}</dt>
                            <dd class="tabular text-ink">
                                {{ estimate.costAmount === null ? t('smsMarketing.estimate.noRate') : format.money(estimate.costAmount, estimate.currency ?? undefined) }}
                            </dd>
                        </div>
                        <p class="text-ink-subtle">
                            {{ estimate.exact ? t('smsMarketing.estimate.exact') : t('smsMarketing.estimate.approximate') }}
                        </p>
                        <p v-if="estimate.tooLarge" class="text-danger" role="alert">
                            {{ t('smsMarketing.estimate.tooLarge', { max: format.number(form.limits.maxAudience) }) }}
                        </p>
                    </dl>
                </div>
            </Card>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" variant="primary" :loading="draft.processing" :disabled="invalid">{{ t('smsMarketing.common.save') }}</Button>
                <Button
                    variant="ghost"
                    :href="campaign ? route('sms_marketing.campaigns.show', { campaign: campaign.id }) : route('sms_marketing.campaigns.index')"
                >
                    {{ t('smsMarketing.common.cancel') }}
                </Button>
            </div>
        </div>
    </form>
</template>

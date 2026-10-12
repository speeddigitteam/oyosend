<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import SocialFormDialog from './SocialFormDialog.vue';
import type { CampaignRow, CampaignStatus } from './types';

/** Creates a campaign or edits `campaign`. */
const props = defineProps<{ open: boolean; campaign: CampaignRow | null; statuses: CampaignStatus[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', description: '', status: 'active' as CampaignStatus, starts_on: '', ends_on: '' });
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: t(`social.status.campaign.${value}`) })));
const datesInvalid = computed(() => form.starts_on !== '' && form.ends_on !== '' && form.ends_on < form.starts_on);

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({
            name: props.campaign?.name ?? '',
            description: props.campaign?.description ?? '',
            status: props.campaign?.status ?? 'active',
            starts_on: props.campaign?.startsOn ?? '',
            ends_on: props.campaign?.endsOn ?? '',
        });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        description: data.description.trim() === '' ? null : data.description.trim(),
        status: data.status,
        starts_on: data.starts_on === '' ? null : data.starts_on,
        ends_on: data.ends_on === '' ? null : data.ends_on,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.campaign) {
        form.put(route('social.library.campaigns.update', { campaign: props.campaign.id }), options);
    } else {
        form.post(route('social.library.campaigns.store'), options);
    }
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        :title="campaign ? t('social.library.campaigns.edit') : t('social.library.campaigns.add')"
        :submit-label="t('social.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === '' || datesInvalid"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('social.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Textarea v-model="form.description" :label="t('social.fields.description')" maxlength="2000" optional :error="form.errors.description" />
        <Select v-model="form.status" :label="t('social.fields.status')" :options="statusOptions" :error="form.errors.status" />
        <div class="grid gap-4 sm:grid-cols-2">
            <Input v-model="form.starts_on" type="date" :label="t('social.fields.startsOn')" optional :error="form.errors.starts_on" />
            <Input
                v-model="form.ends_on"
                type="date"
                :label="t('social.fields.endsOn')"
                optional
                :error="form.errors.ends_on ?? (datesInvalid ? t('social.library.campaigns.datesInvalid') : undefined)"
            />
        </div>
    </SocialFormDialog>
</template>

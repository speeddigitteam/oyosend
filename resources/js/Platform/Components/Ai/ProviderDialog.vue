<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { AiDriver, AiDriverDefinition, AiProviderAccount } from './providers';

/**
 * Add or edit one AI provider account. The parent mounts it with `v-if`, so the form always starts from the
 * account it was opened for. The API key field starts empty and is write-only: empty on edit keeps the stored key.
 */
const props = defineProps<{
    drivers: AiDriverDefinition[];
    /** null = a new account. */
    account: AiProviderAccount | null;
}>();

const open = defineModel<boolean>('open', { default: true });

const { t } = useI18n();

const form = useForm({
    driver: (props.account?.driver ?? props.drivers[0]?.value ?? 'anthropic') as AiDriver,
    name: props.account?.name ?? '',
    model: props.account?.model ?? '',
    transcription_model: props.account?.transcriptionModel ?? '',
    base_url: props.account?.baseUrl ?? '',
    is_enabled: props.account?.enabled ?? true,
    api_key: '',
});

const isEdit = computed(() => props.account !== null);
const definition = computed(() => props.drivers.find((d) => d.value === form.driver));

const driverOptions = computed<SelectOption[]>(() =>
    props.drivers.map((d) => ({ value: d.value, label: t(`platformAi.drivers.${d.value}`) })),
);

const keyHint = computed(() => {
    if (isEdit.value && props.account?.hasKey) {
        return t('platformAi.dialog.keyStored');
    }

    return definition.value?.requiresKey ? t('platformAi.dialog.keyRequired') : t('platformAi.dialog.keyOptional');
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        base_url: data.base_url.trim() === '' ? null : data.base_url.trim(),
        // Only a driver that can transcribe sends a transcription model.
        transcription_model: definition.value?.supportsTranscription && data.transcription_model.trim() !== '' ? data.transcription_model.trim() : null,
        api_key: data.api_key.trim() === '' ? null : data.api_key,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        // The key is not kept in the page state after a request, successful or not.
        onFinish: () => {
            form.api_key = '';
        },
    };

    if (props.account) {
        form.put(route('platform.ai.update', { account: props.account.id }), options);
    } else {
        form.post(route('platform.ai.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :persistent="form.processing"
        :title="isEdit ? t('platformAi.dialog.editTitle') : t('platformAi.dialog.addTitle')"
        :description="t('platformAi.dialog.description')"
    >
        <form id="ai-provider-form" class="flex flex-col gap-4" novalidate autocomplete="off" @submit.prevent="submit">
            <Select
                v-model="form.driver"
                name="driver"
                :label="t('platformAi.dialog.driver')"
                :options="driverOptions"
                :disabled="isEdit"
                :error="form.errors.driver"
            />
            <Input
                v-model="form.name"
                name="name"
                autocomplete="off"
                :label="t('platformAi.dialog.name')"
                :error="form.errors.name"
                maxlength="80"
                required
            />
            <Input
                v-model="form.model"
                name="model"
                autocomplete="off"
                mono
                :label="t('platformAi.dialog.model')"
                :hint="definition?.supportsTranscription ? `${t('platformAi.dialog.modelHint')} ${t('platformAi.dialog.modelOptional')}` : t('platformAi.dialog.modelHint')"
                :error="form.errors.model"
                :optional="definition?.supportsTranscription"
                maxlength="120"
                :required="!definition?.supportsTranscription"
            />
            <Input
                v-if="definition?.supportsTranscription"
                v-model="form.transcription_model"
                name="transcription_model"
                autocomplete="off"
                mono
                optional
                :label="t('platformAi.dialog.transcriptionModel')"
                :hint="t('platformAi.dialog.transcriptionModelHint')"
                :error="form.errors.transcription_model"
                maxlength="120"
            />
            <Input
                v-model="form.base_url"
                name="base_url"
                type="url"
                autocomplete="off"
                mono
                :label="t('platformAi.dialog.baseUrl')"
                :hint="definition?.requiresBaseUrl ? t('platformAi.dialog.baseUrlRequired') : t('platformAi.dialog.baseUrlOptional')"
                :error="form.errors.base_url"
                :optional="!definition?.requiresBaseUrl"
                maxlength="255"
            />
            <PasswordInput
                v-model="form.api_key"
                name="api_key"
                autocomplete="new-password"
                :label="t('platformAi.dialog.apiKey')"
                :hint="keyHint"
                :error="form.errors.api_key"
                maxlength="2048"
            />
            <Checkbox v-model="form.is_enabled" :label="t('platformAi.dialog.enabled')" :description="t('platformAi.dialog.enabledHint')" />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" form="ai-provider-form" variant="primary" :loading="form.processing">
                {{ t('common.actions.save') }}
            </Button>
        </template>
    </Modal>
</template>

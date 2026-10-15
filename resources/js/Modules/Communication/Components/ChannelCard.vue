<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import { stateTone } from './communicationFormat';
import type { ChannelKey, ChannelState } from './types';
import { useCommunicationLabels } from './useCommunicationLabels';

export interface ProviderField {
    key: string;
    label: string;
    type: 'text' | 'select' | 'integer';
    required: boolean;
    options?: string[];
    maxLength?: number;
}

export interface ProviderOption {
    key: string;
    label: string;
    isTestDouble: boolean;
    supportsWebhooks: boolean;
    fields: ProviderField[];
}

export interface ChannelDescription {
    channel: ChannelKey;
    provider: string;
    providerLabel: string;
    isTestDouble: boolean;
    enabled: boolean;
    saved: boolean;
    state: ChannelState;
    settings: Record<string, string | number | boolean | null>;
    credentialRef: string | null;
    webhookSecretRef: string | null;
    supportsWebhooks: boolean;
    rateLimitPerMinute: number | null;
    rateLimitCeiling: number;
    providers: ProviderOption[];
}

const props = defineProps<{
    channel: ChannelDescription;
    credentialRefs: string[];
    /** Operator-managed accounts: reference => account name. */
    credentialLabels?: Record<string, string>;
}>();

const { t } = useI18n();
const { label } = useCommunicationLabels();

const form = useForm({
    provider: props.channel.provider,
    is_enabled: props.channel.enabled,
    settings: Object.fromEntries(props.channel.providers.flatMap((provider) => provider.fields.map((field) => [field.key, String(props.channel.settings[field.key] ?? '')]))) as Record<string, string>,
    credential_ref: props.channel.credentialRef ?? '',
    webhook_secret_ref: props.channel.webhookSecretRef ?? '',
    rate_limit_per_minute: props.channel.rateLimitPerMinute === null ? '' : String(props.channel.rateLimitPerMinute),
});

const selected = computed(() => props.channel.providers.find((provider) => provider.key === form.provider));
const onlyNone = computed(() => props.channel.providers.every((provider) => provider.key === 'none'));
const isNone = computed(() => form.provider === 'none');
const isPlatformMail = computed(() => form.provider === 'mail');
const needsCredential = computed(() => selected.value !== undefined && !isNone.value && !isPlatformMail.value && !selected.value.isTestDouble);

const providerOptions = computed(() =>
    props.channel.providers.map((provider) => ({
        value: provider.key,
        label: provider.isTestDouble ? `${provider.label} (${t('communication.settings.testDouble')})` : provider.label,
    })),
);

function referenceOptions(current: string): { value: string; label: string }[] {
    const names = props.credentialRefs.includes(current) || current === '' ? props.credentialRefs : [current, ...props.credentialRefs];

    return [{ value: '', label: t('communication.settings.credentialNone') }, ...names.map((name) => ({ value: name, label: props.credentialLabels?.[name] ?? name }))];
}

function selectOptions(field: ProviderField): { value: string; label: string }[] {
    return (field.options ?? []).map((option) => ({ value: option, label: option }));
}

function save(): void {
    form
        .transform((data) => {
            const fields = selected.value?.fields ?? [];

            return {
                provider: data.provider,
                is_enabled: data.is_enabled,
                settings: Object.fromEntries(fields.map((field) => [field.key, data.settings[field.key] ?? ''])),
                credential_ref: needsCredential.value && data.credential_ref !== '' ? data.credential_ref : null,
                webhook_secret_ref: needsCredential.value && selected.value?.supportsWebhooks && data.webhook_secret_ref !== '' ? data.webhook_secret_ref : null,
                rate_limit_per_minute: data.rate_limit_per_minute === '' ? null : Number(data.rate_limit_per_minute),
            };
        })
        .put(route('communication.settings.channels.update', { channel: props.channel.channel }), { preserveScroll: true });
}
</script>

<template>
    <Card :title="label('channel', channel.channel)" :data-testid="`channel-${channel.channel}`">
        <template #actions>
            <Badge v-if="channel.isTestDouble" tone="warn">{{ t('communication.settings.testDouble') }}</Badge>
            <Badge :tone="stateTone(channel.state)" dot>{{ label('channelState', channel.state) }}</Badge>
        </template>

        <p v-if="onlyNone" class="text-sm text-ink-muted">{{ t('communication.settings.noProvider') }}</p>

        <form v-else class="@container grid gap-4" @submit.prevent="save">
            <p v-if="isPlatformMail" class="text-sm text-ink-muted">{{ t('communication.settings.emailPlatform') }}</p>
            <p v-if="selected?.isTestDouble" class="text-sm text-warn" role="status">{{ t('communication.settings.testDoubleNote') }}</p>
            <p v-if="isNone" class="text-sm text-ink-muted">{{ t('communication.settings.noProvider') }}</p>

            <Select
                v-model="form.provider"
                :options="providerOptions"
                :label="t('communication.settings.provider')"
                :error="form.errors.provider"
            />

            <div v-if="selected && selected.fields.length > 0" class="grid gap-4 @lg:grid-cols-2">
                <template v-for="field in selected.fields" :key="field.key">
                    <Select
                        v-if="field.type === 'select'"
                        v-model="form.settings[field.key]"
                        :options="selectOptions(field)"
                        :label="field.label"
                        :optional="!field.required"
                        :error="form.errors[`settings.${field.key}`]"
                    />
                    <Input
                        v-else
                        v-model="form.settings[field.key]"
                        :type="field.type === 'integer' ? 'number' : 'text'"
                        :label="field.label"
                        :optional="!field.required"
                        :required="field.required"
                        :maxlength="field.maxLength"
                        :error="form.errors[`settings.${field.key}`]"
                    />
                </template>
            </div>

            <div v-if="needsCredential" class="grid gap-4 @lg:grid-cols-2">
                <Select
                    v-model="form.credential_ref"
                    :options="referenceOptions(form.credential_ref)"
                    :label="t('communication.settings.credentialRef')"
                    :hint="t('communication.settings.credentialRefHint')"
                    :error="form.errors.credential_ref"
                    optional
                />
                <Select
                    v-if="selected?.supportsWebhooks"
                    v-model="form.webhook_secret_ref"
                    :options="referenceOptions(form.webhook_secret_ref)"
                    :label="t('communication.settings.webhookRef')"
                    :hint="t('communication.settings.webhookRefHint')"
                    :error="form.errors.webhook_secret_ref"
                    optional
                />
            </div>

            <div class="grid gap-4 @lg:grid-cols-2">
                <Input
                    v-model="form.rate_limit_per_minute"
                    class="max-w-xs"
                    type="number"
                    min="1"
                    :max="channel.rateLimitCeiling"
                    :label="t('communication.settings.rateLimit')"
                    :hint="t('communication.settings.rateLimitHint', { max: channel.rateLimitCeiling })"
                    :error="form.errors.rate_limit_per_minute"
                    optional
                />
            </div>

            <Checkbox v-model="form.is_enabled" :label="t('communication.settings.enabled')" />

            <div class="flex justify-end">
                <Button type="submit" variant="primary" :loading="form.processing">{{ t('communication.settings.saveChannel') }}</Button>
            </div>
        </form>
    </Card>
</template>

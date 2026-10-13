<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import {
    buildPayload,
    emptySecrets,
    initialSettings,
    type MessagingAccount,
    type MessagingChannel,
    type ProviderDefinition,
    type ProviderField,
} from './accounts';

/**
 * Add or edit one account. The parent mounts it with `v-if`, so the form
 * always starts from the account it was opened for.
 */
const props = defineProps<{
    channel: MessagingChannel;
    /** The channel's providers only. */
    providers: ProviderDefinition[];
    workspaces: { id: string; name: string }[];
    /** null = a new account. */
    account: MessagingAccount | null;
}>();

const open = defineModel<boolean>('open', { default: true });

const { t, te } = useI18n();

const first = props.providers.find((p) => p.key === props.account?.provider) ?? props.providers[0];

const form = useForm({
    provider: first?.key ?? '',
    name: props.account?.name ?? '',
    is_enabled: props.account?.enabled ?? true,
    settings: initialSettings(first?.fields ?? [], props.account?.settings),
    secrets: emptySecrets(first?.fields ?? []),
    allWorkspaces: props.account ? props.account.tenantIds === null : true,
    tenantIds: [...(props.account?.tenantIds ?? [])],
});

const provider = computed(() => props.providers.find((p) => p.key === form.provider));
const fields = computed<ProviderField[]>(() => provider.value?.fields ?? []);
const plainFields = computed(() => fields.value.filter((f) => f.type !== 'secret'));
const secretFields = computed(() => fields.value.filter((f) => f.type === 'secret'));

const isEdit = computed(() => props.account !== null);

function providerLabel(p: ProviderDefinition): string {
    const key = `platformMessaging.providers.${p.key}`;

    return te(key) ? t(key) : p.label;
}

function fieldLabel(field: ProviderField): string {
    const key = `platformMessaging.fields.${field.key}`;

    return te(key) ? t(key) : field.label;
}

function optionLabel(value: string): string {
    const key = `platformMessaging.options.${value}`;

    return te(key) ? t(key) : value;
}

const providerOptions = computed<SelectOption[]>(() =>
    props.providers.map((p) => ({ value: p.key, label: providerLabel(p) })),
);

function selectOptions(field: ProviderField): SelectOption[] {
    const options = (field.options ?? []).map((value) => ({ value, label: optionLabel(value) }));

    // An optional select can be left unset.
    return field.required ? options : [{ value: '', label: t('platformMessaging.dialog.notSet') }, ...options];
}

const availabilityOptions = computed<Segment<'all' | 'selected'>[]>(() => [
    { value: 'all', label: t('platformMessaging.availability.all') },
    { value: 'selected', label: t('platformMessaging.availability.selected') },
]);

const availability = computed<'all' | 'selected'>({
    get: () => {
        if (form.allWorkspaces) {
            return 'all';
        }

        return 'selected';
    },
    set: (value) => {
        form.allWorkspaces = value === 'all';
    },
});

function onProviderChange(): void {
    // Another provider has other fields; what was typed for the old one does not carry over.
    const definition = provider.value;
    const stored = definition && props.account?.provider === definition.key ? props.account.settings : {};

    form.settings = initialSettings(definition?.fields ?? [], stored);
    form.secrets = emptySecrets(definition?.fields ?? []);
    form.clearErrors();
}

function toggleWorkspace(id: string, on: boolean): void {
    form.tenantIds = on ? [...form.tenantIds, id] : form.tenantIds.filter((x) => x !== id);
}

function secretPlaceholder(field: ProviderField): string | undefined {
    return props.account?.provider === form.provider && props.account.secretsSet.includes(field.key)
        ? t('platformMessaging.dialog.secretStored')
        : undefined;
}

function error(path: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[path];
}

function submit(): void {
    form.transform((data) =>
        buildPayload({
            channel: props.channel,
            provider: data.provider,
            name: data.name,
            enabled: data.is_enabled,
            fields: fields.value,
            settings: data.settings,
            secrets: data.secrets,
            allWorkspaces: data.allWorkspaces,
            tenantIds: data.tenantIds,
        }),
    );

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.account) {
        form.put(route('platform.messaging.update', { account: props.account.id }), options);
    } else {
        form.post(route('platform.messaging.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        size="lg"
        :persistent="form.processing"
        :title="isEdit ? t('platformMessaging.dialog.editTitle') : t('platformMessaging.dialog.addTitle')"
        :description="t(`platformMessaging.channels.${channel}.title`)"
    >
        <form id="messaging-account-form" novalidate class="flex flex-col gap-4" @submit.prevent="submit">
            <Select
                v-model="form.provider"
                name="provider"
                :label="t('platformMessaging.dialog.provider')"
                :options="providerOptions"
                :error="error('provider')"
                :disabled="isEdit"
                @change="onProviderChange"
            />

            <Input
                v-model="form.name"
                name="name"
                autocomplete="off"
                maxlength="120"
                :label="t('platformMessaging.dialog.name')"
                :hint="t('platformMessaging.dialog.nameHint')"
                :error="error('name')"
                required
            />

            <Checkbox
                v-model="form.is_enabled"
                :label="t('platformMessaging.dialog.enabled')"
                :description="t('platformMessaging.dialog.enabledHint')"
            />

            <div v-if="fields.length > 0" class="flex flex-col gap-4 border-t border-line pt-4">
                <p class="eyebrow">{{ t('platformMessaging.dialog.connection') }}</p>

                <div class="grid content-start gap-4 sm:grid-cols-2">
                    <template v-for="field in plainFields" :key="`${form.provider}-${field.key}`">
                        <Select
                            v-if="field.type === 'select'"
                            v-model="form.settings[field.key]"
                            :name="`settings.${field.key}`"
                            :label="fieldLabel(field)"
                            :options="selectOptions(field)"
                            :error="error(`settings.${field.key}`)"
                            :optional="!field.required"
                        />
                        <Input
                            v-else
                            v-model="form.settings[field.key]"
                            :name="`settings.${field.key}`"
                            :label="fieldLabel(field)"
                            :error="error(`settings.${field.key}`)"
                            :inputmode="field.type === 'integer' ? 'numeric' : undefined"
                            :maxlength="field.maxLength"
                            :optional="!field.required"
                            autocomplete="off"
                        />
                    </template>
                </div>

                <template v-if="secretFields.length > 0">
                    <div class="grid content-start gap-4 sm:grid-cols-2">
                        <Input
                            v-for="field in secretFields"
                            :key="`${form.provider}-${field.key}`"
                            v-model="form.secrets[field.key]"
                            type="password"
                            autocomplete="new-password"
                            :name="`secrets.${field.key}`"
                            :label="fieldLabel(field)"
                            :placeholder="secretPlaceholder(field)"
                            :error="error(`secrets.${field.key}`)"
                            :optional="!field.required"
                        />
                    </div>
                    <p class="text-[0.8125rem] text-ink-subtle">{{ t('platformMessaging.secretsNote') }}</p>
                </template>
            </div>

            <div class="flex flex-col gap-3 border-t border-line pt-4">
                <Field :label="t('platformMessaging.dialog.availability')" :error="error('tenant_ids')">
                    <div>
                        <SegmentedControl
                            v-model="availability"
                            :options="availabilityOptions"
                            :label="t('platformMessaging.dialog.availability')"
                        />
                    </div>
                </Field>

                <fieldset v-if="!form.allWorkspaces" class="flex flex-col gap-2">
                    <legend class="mb-1 text-[0.8125rem] text-ink-muted">{{ t('platformMessaging.dialog.workspaces') }}</legend>
                    <p v-if="workspaces.length === 0" class="text-[0.8125rem] text-ink-subtle">
                        {{ t('platformMessaging.dialog.noWorkspaces') }}
                    </p>
                    <div v-else class="glass-well grid max-h-48 gap-2 overflow-y-auto rounded-control p-3 sm:grid-cols-2">
                        <Checkbox
                            v-for="workspace in workspaces"
                            :key="workspace.id"
                            :model-value="form.tenantIds.includes(workspace.id)"
                            :label="workspace.name"
                            @update:model-value="toggleWorkspace(workspace.id, $event)"
                        />
                    </div>
                </fieldset>
            </div>
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" form="messaging-account-form" variant="primary" :loading="form.processing">
                {{ t('common.actions.save') }}
            </Button>
        </template>
    </Modal>
</template>

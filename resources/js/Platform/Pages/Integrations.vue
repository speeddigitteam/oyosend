<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Megaphone, Share2 } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: PlatformLayout });

/** An integration's status. Secret values are never sent to the page. */
type Integration = {
    group: string;
    name: string;
    /** Write-only secret fields, in display order. */
    secretFields: string[];
    /** Non-secret public identifier fields, shown back and prefilled. */
    publicFields: string[];
    /** Non-secret on/off switches (e.g. Instagram publishing), shown back. */
    flagFields?: string[];
    /** True when the operator set credentials in the panel (rather than the environment). */
    managed: boolean;
    source: 'panel' | 'environment' | 'none' | 'switch';
    /** The stored public identifier values, safe to show. */
    publicValues: Record<string, string>;
    /** The current value of each switch: stored in the panel, or the environment default. */
    flagValues?: Record<string, boolean>;
    /** Secret fields that already hold a value; their inputs may be left blank to keep them. */
    storedSecretFields: string[];
    enabled: boolean;
    updatedAt: string | null;
};

const props = defineProps<{
    integrations: Integration[];
}>();

const { t, te } = useI18n();

type IntegrationForm = {
    enabled: boolean;
    secrets: Record<string, string>;
    public: Record<string, string>;
    flags: Record<string, boolean>;
};

function keyOf(integration: Integration): string {
    return `${integration.group}/${integration.name}`;
}

// One write-only form per integration. Secret fields start blank: a blank field keeps the stored
// value, so the operator can change a public id without re-typing a secret.
const forms: Record<string, ReturnType<typeof useForm<IntegrationForm>>> = {};
props.integrations.forEach((integration) => {
    forms[keyOf(integration)] = useForm<IntegrationForm>({
        enabled: integration.enabled,
        secrets: Object.fromEntries(integration.secretFields.map((field) => [field, ''])),
        public: Object.fromEntries(integration.publicFields.map((field) => [field, integration.publicValues[field] ?? ''])),
        flags: Object.fromEntries((integration.flagFields ?? []).map((field) => [field, integration.flagValues?.[field] ?? false])),
    });
});

/** The integrations of one app together, in the order the server sent them. */
const groups = computed(() => {
    const byGroup = new Map<string, Integration[]>();

    props.integrations.forEach((integration) => {
        byGroup.set(integration.group, [...(byGroup.get(integration.group) ?? []), integration]);
    });

    return [...byGroup.entries()].map(([key, items]) => ({ key, items }));
});

const GROUP_ICONS: Record<string, Component> = { social: Share2, meta_ads: Megaphone };

function groupIcon(group: string): Component {
    return GROUP_ICONS[group] ?? Share2;
}

function groupName(group: string): string {
    const key = `catalogue.modules.${group}`;

    return te(key) ? t(key) : group;
}

function formId(integration: Integration): string {
    return `integration-${integration.group}-${integration.name}`;
}

function integrationName(integration: Integration): string {
    const key = `platformIntegrations.integrations.${integration.group}.${integration.name}.name`;

    return te(key) ? t(key) : integration.name;
}

function integrationDescription(integration: Integration): string {
    return t(`platformIntegrations.integrations.${integration.group}.${integration.name}.description`);
}

function fieldLabel(field: string): string {
    return t(`platformIntegrations.fields.${field}`);
}

function flagLabel(field: string): string {
    return t(`platformIntegrations.flags.${field}.label`);
}

function flagHint(field: string): string {
    return t(`platformIntegrations.flags.${field}.hint`);
}

function secretPlaceholder(integration: Integration, field: string): string | undefined {
    // Already stored: the operator may leave it blank to keep it.
    return integration.storedSecretFields.includes(field) ? t('platformIntegrations.secretStored') : undefined;
}

function save(integration: Integration): void {
    const key = keyOf(integration);

    forms[key].put(route('platform.integrations.update', { group: integration.group, name: integration.name }), {
        preserveScroll: true,
        onSuccess: () => {
            // Clear the secret inputs again; they were write-only.
            integration.secretFields.forEach((field) => {
                forms[key].secrets[field] = '';
            });
            forms[key].defaults();
        },
    });
}

function clear(integration: Integration): void {
    router.delete(route('platform.integrations.clear', { group: integration.group, name: integration.name }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('shell.nav.sections.platform')"
            :title="t('shell.nav.platformIntegrations')"
            :description="t('platformIntegrations.description')"
        />

        <Card v-if="integrations.length === 0" :title="t('platformIntegrations.title')" :description="t('platformIntegrations.intro')">
            <EmptyState :title="t('platformIntegrations.none')" :description="t('platformIntegrations.noneBody')" compact>
                <template #icon><Share2 /></template>
            </EmptyState>
        </Card>

        <template v-else>
            <p class="mb-4 text-[0.8125rem] text-ink-subtle">{{ t('platformIntegrations.intro') }}</p>

            <!-- Full width like Settings: one box per integration, grouped by the app that uses it. Each box lays out
                 its own fields by its own width (container queries). -->
            <section v-for="group in groups" :key="group.key" class="mb-5" :aria-label="groupName(group.key)">
                <h2 class="eyebrow mb-2">{{ groupName(group.key) }}</h2>

                <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
                    <Card v-for="integration in group.items" :key="keyOf(integration)" data-testid="integration">
                        <template #header>
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-text">
                                    <component :is="groupIcon(integration.group)" class="size-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-[0.9375rem] font-semibold tracking-tight wrap-break-word text-ink">{{ integrationName(integration) }}</h3>
                                    <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ integrationDescription(integration) }}</p>
                                </div>
                            </div>
                        </template>
                        <template #actions>
                            <Badge :tone="integration.managed ? 'ok' : 'neutral'" dot>
                                {{ t(`platformIntegrations.source.${integration.source}`) }}
                            </Badge>
                        </template>

                        <form :id="formId(integration)" novalidate class="@container flex flex-col gap-4" @submit.prevent="save(integration)">
                            <div class="grid content-start gap-4 @lg:grid-cols-2">
                                <Field
                                    v-for="field in integration.publicFields"
                                    :key="field"
                                    :label="fieldLabel(field)"
                                    :hint="t('platformIntegrations.publicHint')"
                                    :error="forms[keyOf(integration)].errors[`public.${field}` as keyof IntegrationForm]"
                                >
                                    <Input
                                        v-model="forms[keyOf(integration)].public[field]"
                                        :name="`${integration.group}.${integration.name}.${field}`"
                                        autocomplete="off"
                                    />
                                </Field>

                                <Field
                                    v-for="field in integration.secretFields"
                                    :key="field"
                                    :label="fieldLabel(field)"
                                    :error="forms[keyOf(integration)].errors[`secrets.${field}` as keyof IntegrationForm]"
                                >
                                    <Input
                                        v-model="forms[keyOf(integration)].secrets[field]"
                                        :name="`${integration.group}.${integration.name}.${field}`"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="secretPlaceholder(integration, field)"
                                    />
                                </Field>
                            </div>

                            <Checkbox
                                v-for="field in integration.flagFields ?? []"
                                :key="field"
                                v-model="forms[keyOf(integration)].flags[field]"
                                :label="flagLabel(field)"
                                :description="flagHint(field)"
                            />

                            <Checkbox v-model="forms[keyOf(integration)].enabled" :label="t('platformIntegrations.enabled')" />
                        </form>

                        <template #footer>
                            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                                <Button v-if="integration.managed" type="button" variant="ghost" @click="clear(integration)">
                                    {{ t('platformIntegrations.clear') }}
                                </Button>
                                <Button type="submit" :form="formId(integration)" variant="primary" :loading="forms[keyOf(integration)].processing">
                                    {{ t('platformIntegrations.save') }}
                                </Button>
                            </div>
                        </template>
                    </Card>
                </div>
            </section>
        </template>
    </div>
</template>

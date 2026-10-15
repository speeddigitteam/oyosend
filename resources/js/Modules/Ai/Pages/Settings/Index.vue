<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { stateTone } from '../../Components/aiFormat';
import AiNav from '../../Components/AiNav.vue';
import AvailabilityNotice from '../../Components/AvailabilityNotice.vue';
import type { Availability } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: {
        enabled: boolean;
        chatEnabled: boolean;
        draftingEnabled: boolean;
        automationEnabled: boolean;
        dataScopes: string[];
        retentionDays: number;
        redactPii: boolean;
    };
    options: {
        dataScopes: string[];
        retention: { min: number; max: number };
        platformRedaction: boolean;
    };
    availability: Availability;
}>();

const { t, te } = useI18n();

const form = useForm({
    enabled: props.settings.enabled,
    chat_enabled: props.settings.chatEnabled,
    drafting_enabled: props.settings.draftingEnabled,
    automation_enabled: props.settings.automationEnabled,
    data_scopes: [...props.settings.dataScopes],
    retention_days: String(props.settings.retentionDays),
    redact_pii: props.settings.redactPii,
});

function scopeLabel(scope: string): string {
    return te(`ai.settings.scope.${scope}`) ? t(`ai.settings.scope.${scope}`) : scope;
}

function featureLabel(feature: string): string {
    return te(`ai.feature.${feature}`) ? t(`ai.feature.${feature}`) : feature;
}

function stateLabel(state: string): string {
    return te(`ai.state.${state}.title`) ? t(`ai.state.${state}.title`) : state;
}

function scopeOn(scope: string): boolean {
    return form.data_scopes.includes(scope);
}

function toggleScope(scope: string, on: boolean): void {
    form.data_scopes = on ? [...new Set([...form.data_scopes, scope])] : form.data_scopes.filter((item) => item !== scope);
}

function save(): void {
    form.transform((data) => ({ ...data, retention_days: Number(data.retention_days) })).put(route('ai.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('ai.eyebrow')" :title="t('ai.settings.title')" :description="t('ai.settings.description')" />
    <AiNav current="settings" />
    <AvailabilityNotice :availability="availability" />

    <!-- Full width like the platform Settings page: the provider across the top, the three setting boxes side by side
         on wide screens. Each box lays out its own content by its own width (container queries). -->
    <form class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3" @submit.prevent="save">
        <Card class="lg:col-span-2 xl:col-span-3" :title="t('ai.settings.provider')" :description="t('ai.settings.providerHint')">
            <dl class="grid gap-4 sm:grid-cols-3" data-testid="provider">
                <div>
                    <dt class="eyebrow">{{ t('ai.settings.credential') }}</dt>
                    <dd class="mt-1.5">
                        <Badge :tone="availability.configured ? 'ok' : 'info'" dot>
                            {{ availability.configured ? t('ai.settings.credentialYes') : t('ai.settings.credentialNo') }}
                        </Badge>
                    </dd>
                </div>
                <div v-if="availability.configured">
                    <dt class="eyebrow">{{ t('ai.settings.providerName') }}</dt>
                    <dd class="mt-1.5 text-sm text-ink">{{ availability.provider }}</dd>
                </div>
                <div v-if="availability.configured">
                    <dt class="eyebrow">{{ t('ai.settings.model') }}</dt>
                    <dd class="mt-1.5 font-mono text-sm text-ink">{{ availability.model }}</dd>
                </div>
            </dl>
            <h3 class="eyebrow mt-5 mb-2">{{ t('ai.settings.features') }}</h3>
            <!-- The name and the state wrap onto two lines when they do not fit: a long state never hides the name. -->
            <ul class="grid gap-2 sm:grid-cols-3" data-testid="features">
                <li
                    v-for="(feature, key) in availability.features"
                    :key="key"
                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-control bg-hover px-3 py-2.5 text-sm"
                >
                    <span class="font-medium text-ink">{{ featureLabel(String(key)) }}</span>
                    <Badge :tone="stateTone(feature.state)">{{ stateLabel(feature.state) }}</Badge>
                </li>
            </ul>
        </Card>

        <Card :title="t('ai.settings.switches')" :description="t('ai.settings.switchesHint')">
            <div class="grid gap-4">
                <Checkbox v-model="form.enabled" :label="t('ai.settings.enabled')" :description="t('ai.settings.enabledHint')" />
                <Checkbox v-model="form.chat_enabled" :label="t('ai.settings.chat')" :description="t('ai.settings.chatHint')" />
                <Checkbox v-model="form.drafting_enabled" :label="t('ai.settings.drafting')" :description="t('ai.settings.draftingHint')" />
                <Checkbox v-model="form.automation_enabled" :label="t('ai.settings.automation')" :description="t('ai.settings.automationHint')" />
            </div>
        </Card>

        <Card :title="t('ai.settings.scopes')" :description="t('ai.settings.scopesHint')">
            <p v-if="options.dataScopes.length === 0" class="text-sm text-ink-muted">{{ t('ai.settings.scopesEmpty') }}</p>
            <div v-else class="grid gap-3">
                <Checkbox
                    v-for="scope in options.dataScopes"
                    :key="scope"
                    :model-value="scopeOn(scope)"
                    :label="scopeLabel(scope)"
                    @update:model-value="toggleScope(scope, $event)"
                />
            </div>
            <p v-if="form.errors.data_scopes" class="mt-2 text-sm text-danger">{{ form.errors.data_scopes }}</p>
        </Card>

        <Card
            class="lg:col-span-2 xl:col-span-1"
            :title="t('ai.settings.retention')"
            :description="t('ai.settings.retentionHint', { min: options.retention.min, max: options.retention.max })"
        >
            <div class="grid gap-4">
                <Input
                    v-model="form.retention_days"
                    class="max-w-xs"
                    type="number"
                    :min="options.retention.min"
                    :max="options.retention.max"
                    :label="t('ai.settings.retention')"
                    :error="form.errors.retention_days"
                    required
                />
                <Checkbox
                    v-model="form.redact_pii"
                    :label="t('ai.settings.redact')"
                    :description="options.platformRedaction ? t('ai.settings.redactPlatform') : t('ai.settings.redactHint')"
                    :disabled="options.platformRedaction"
                />
            </div>
        </Card>

        <div class="flex justify-end lg:col-span-2 xl:col-span-3">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('ai.settings.save') }}</Button>
        </div>
    </form>
</template>

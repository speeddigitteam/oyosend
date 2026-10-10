<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, LockKeyhole } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';
import AppSettingField from './AppSettingField.vue';
import type { AppSetting } from './types';

type SettingValue = string | number | boolean | null;

const props = defineProps<{ appKey: string; settings: AppSetting[] }>();

const { t } = useI18n();
const { can } = useAccess();
const canManage = computed(() => can('core.apps.manage'));

function initialValue(setting: AppSetting): SettingValue {
    if (setting.type === 'secret') {
        return '';
    }

    if (setting.type === 'boolean') {
        return setting.value === true;
    }

    return setting.value ?? '';
}

const form = useForm<{ settings: Record<string, SettingValue> }>({
    settings: Object.fromEntries(props.settings.map((setting) => [setting.key, initialValue(setting)])),
});

function errorFor(setting: AppSetting): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`settings.${setting.key}`];
}

function submit(): void {
    form.put(route('core.apps.settings.update', { app: props.appKey }), {
        preserveScroll: true,
        onSuccess: () => {
            for (const setting of props.settings) {
                if (setting.type === 'secret') {
                    form.settings[setting.key] = '';
                }
            }

            form.defaults({ settings: { ...form.settings } });
        },
    });
}

function displayValue(setting: AppSetting): string {
    if (!setting.hasValue) {
        return t('appStore.settings.notConfigured');
    }

    if (setting.type === 'secret') {
        return t('appStore.settings.configured');
    }

    if (setting.type === 'boolean') {
        return t(setting.value === true ? 'appStore.settings.enabled' : 'appStore.settings.disabled');
    }

    if (setting.type === 'select' && typeof setting.value === 'string') {
        return setting.options[setting.value] ?? setting.value;
    }

    return String(setting.value ?? '');
}
</script>

<template>
    <Card :title="t('appStore.settings.title')" :description="t('appStore.settings.description')">
        <form v-if="canManage" class="space-y-4" novalidate @submit.prevent="submit">
            <AppSettingField
                v-for="setting in settings"
                :key="setting.key"
                v-model="form.settings[setting.key]"
                :setting="setting"
                :error="errorFor(setting)"
            />

            <p v-if="form.errors.settings" class="text-[0.8125rem] text-danger" role="alert">{{ form.errors.settings }}</p>

            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                <p v-if="form.recentlySuccessful" class="mr-auto flex items-center gap-1.5 text-[0.8125rem] text-ok" role="status">
                    <Check class="size-4" aria-hidden="true" />
                    {{ t('appStore.settings.saved') }}
                </p>
                <p v-else-if="form.isDirty" class="mr-auto text-[0.8125rem] text-ink-subtle">{{ t('appStore.settings.unsaved') }}</p>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">
                    {{ t('appStore.settings.save') }}
                </Button>
            </div>
        </form>

        <dl v-else class="divide-y divide-line">
            <div v-for="setting in settings" :key="setting.key" class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <dt class="text-[0.8125rem] font-medium text-ink">{{ setting.label }}</dt>
                    <p v-if="setting.description" class="mt-0.5 text-xs text-ink-subtle">{{ setting.description }}</p>
                </div>
                <dd class="flex shrink-0 items-center gap-1.5 text-[0.8125rem] text-ink-muted">
                    <LockKeyhole v-if="setting.type === 'secret'" class="size-3.5" aria-hidden="true" />
                    <Badge v-if="setting.type === 'secret'" :tone="setting.hasValue ? 'ok' : 'neutral'">{{ displayValue(setting) }}</Badge>
                    <span v-else>{{ displayValue(setting) }}</span>
                </dd>
            </div>
        </dl>
    </Card>
</template>

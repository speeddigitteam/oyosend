<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import { route } from '@/Core/lib/route';
import type { SignupSummary } from './types';

const props = defineProps<{
    listId: string;
    listActive: boolean;
    signup: SignupSummary;
}>();

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();
const saving = ref(false);

function save(next: { enabled: boolean; embed: boolean; rotate?: boolean }): void {
    saving.value = true;
    router.put(route('email_marketing.lists.signup', { list: props.listId }), { rotate: false, ...next }, { preserveScroll: true, onFinish: () => (saving.value = false) });
}

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toasts.success(t('emailMarketing.signup.copied'));
    } catch {
        toasts.error(t('emailMarketing.signup.copyFailed'));
    }
}
</script>

<template>
    <Card :title="t('emailMarketing.signup.title')" :description="t('emailMarketing.signup.intro')" class="mb-3" data-testid="signup-panel">
        <div class="grid gap-4">
            <Checkbox
                :model-value="signup.enabled"
                :disabled="saving || (!listActive && !signup.enabled)"
                :label="t('emailMarketing.signup.enable')"
                :description="listActive ? t('emailMarketing.signup.enableHint') : t('emailMarketing.signup.archivedHint')"
                @update:model-value="save({ enabled: $event, embed: signup.embed })"
            />

            <template v-if="signup.enabled && signup.url">
                <div class="grid gap-1">
                    <span class="eyebrow">{{ t('emailMarketing.signup.link') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="min-w-0 flex-1 break-all rounded-control bg-control-sunken px-2 py-1.5 text-[0.8125rem] text-ink" data-testid="signup-url">{{ signup.url }}</code>
                        <Button size="sm" @click="copy(signup.url)">{{ t('emailMarketing.signup.copy') }}</Button>
                    </div>
                </div>

                <Checkbox
                    :model-value="signup.embed"
                    :disabled="saving"
                    :label="t('emailMarketing.signup.embed')"
                    :description="t('emailMarketing.signup.embedHint')"
                    @update:model-value="save({ enabled: true, embed: $event })"
                />

                <div v-if="signup.iframe" class="grid gap-1">
                    <span class="eyebrow">{{ t('emailMarketing.signup.snippet') }}</span>
                    <div class="flex flex-wrap items-start gap-2">
                        <code class="min-w-0 flex-1 break-all rounded-control bg-control-sunken px-2 py-1.5 text-[0.8125rem] text-ink" data-testid="signup-iframe">{{ signup.iframe }}</code>
                        <Button size="sm" @click="copy(signup.iframe)">{{ t('emailMarketing.signup.copy') }}</Button>
                    </div>
                </div>

                <div>
                    <Button size="sm" variant="ghost" :loading="saving" @click="save({ enabled: true, embed: signup.embed, rotate: true })">{{ t('emailMarketing.signup.rotate') }}</Button>
                    <p class="mt-1 text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.signup.rotateHint') }}</p>
                </div>
            </template>

            <dl class="grid grid-cols-2 gap-3" data-testid="signup-stats">
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.signup.confirmed') }}</dt>
                    <dd class="figure mt-1 text-xl text-ink">{{ format.number(signup.confirmed) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('emailMarketing.signup.unconfirmed') }}</dt>
                    <dd class="figure mt-1 text-xl text-ink">{{ format.number(signup.unconfirmed) }}</dd>
                </div>
            </dl>

            <p class="text-[0.8125rem] text-ink-muted">{{ t('emailMarketing.signup.consentNote') }}</p>
        </div>
    </Card>
</template>

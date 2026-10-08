<script setup lang="ts">
import { ShieldAlert, ShieldCheck } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

interface SecurityData {
    twoFactorEnabled: boolean;
    recoveryCodesRemaining: number | null;
    lastLoginAt: string | null;
    emailVerified: boolean;
}

defineProps<{ data: SecurityData }>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('dashboard.security.title')" class="h-full">
        <div class="flex items-start gap-4">
            <span
                :class="[
                    'flex size-11 shrink-0 items-center justify-center rounded-full',
                    data.twoFactorEnabled ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn',
                ]"
                aria-hidden="true"
            >
                <ShieldCheck v-if="data.twoFactorEnabled" class="size-5" />
                <ShieldAlert v-else class="size-5" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold text-ink">{{ t('dashboard.security.twoFactor') }}</p>
                    <Badge :tone="data.twoFactorEnabled ? 'ok' : 'warn'" dot>
                        {{ data.twoFactorEnabled ? t('dashboard.security.twoFactorOn') : t('dashboard.security.twoFactorOff') }}
                    </Badge>
                </div>

                <p v-if="!data.twoFactorEnabled" class="mt-1 text-[0.8125rem] text-ink-muted">
                    {{ t('dashboard.security.twoFactorPrompt') }}
                </p>
                <p v-else-if="data.recoveryCodesRemaining !== null" class="mt-1 text-[0.8125rem] text-ink-muted">
                    {{ t('dashboard.security.recoveryCodes', { count: format.number(data.recoveryCodesRemaining) }) }}
                    <span v-if="data.recoveryCodesRemaining <= 2" class="text-warn"> · {{ t('dashboard.security.recoveryLow') }}</span>
                </p>
            </div>
        </div>

        <dl class="mt-5 flex items-baseline justify-between gap-4 border-t border-line pt-4 text-[0.8125rem]">
            <dt class="text-ink-subtle">{{ t('dashboard.security.lastLogin') }}</dt>
            <dd class="tabular text-right text-ink">
                {{ data.lastLoginAt ? format.date(data.lastLoginAt, 'datetime') : t('common.never') }}
            </dd>
        </dl>

        <template #footer>
            <Button size="sm" :variant="data.twoFactorEnabled ? 'secondary' : 'primary'" :href="route('core.settings.security')">
                {{ data.twoFactorEnabled ? t('dashboard.security.review') : t('dashboard.security.enable') }}
            </Button>
        </template>
    </Card>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowUpRight, LogIn, LogOut, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { useHrmFormat } from './hrmFormat';

/** The signed-in person's own HR picture on the dashboard: clock state, leave balance, open requests. */
defineProps<{
    data: { hasProfile: boolean; clockedIn: boolean; leaveBalanceUnits: number; pendingRequests: number; canClock: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();
const busy = ref(false);

function toggle(clockedIn: boolean): void {
    busy.value = true;
    router.post(route(clockedIn ? 'hrm.attendance.clock-out' : 'hrm.attendance.clock-in'), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <Card :title="t('hrm.widget.self.title')" class="h-full" data-testid="hrm-self-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('hrm.me')">
                {{ t('hrm.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState v-if="!data.hasProfile" :title="t('hrm.widget.self.noProfile')" :description="t('hrm.widget.self.noProfileBody')" compact>
            <template #icon><UserRound /></template>
        </EmptyState>

        <div v-else class="grid gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Badge :tone="data.clockedIn ? 'ok' : 'neutral'" dot data-testid="self-clock-state">
                    {{ data.clockedIn ? t('hrm.widget.self.clockedIn') : t('hrm.widget.self.clockedOut') }}
                </Badge>
                <Button v-if="data.canClock" size="sm" :variant="data.clockedIn ? 'secondary' : 'primary'" :loading="busy" @click="toggle(data.clockedIn)">
                    <template #icon>
                        <LogOut v-if="data.clockedIn" class="size-4" aria-hidden="true" />
                        <LogIn v-else class="size-4" aria-hidden="true" />
                    </template>
                    {{ data.clockedIn ? t('hrm.clock.clockOut') : t('hrm.clock.clockIn') }}
                </Button>
            </div>
            <dl class="grid grid-cols-2 gap-x-5 gap-y-3">
                <div>
                    <dt class="eyebrow">{{ t('hrm.widget.self.balance') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink" data-testid="self-balance">{{ hrm.days(data.leaveBalanceUnits) }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('hrm.widget.self.pending') }}</dt>
                    <dd class="figure mt-2 text-2xl" :class="data.pendingRequests > 0 ? 'text-brand-text' : 'text-ink'">
                        {{ format.number(data.pendingRequests) }}
                    </dd>
                </div>
            </dl>
        </div>
    </Card>
</template>

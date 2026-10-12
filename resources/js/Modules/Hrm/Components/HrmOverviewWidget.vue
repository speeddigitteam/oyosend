<script setup lang="ts">
import { ArrowUpRight, UsersRound } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** Workspace-wide HR numbers for the dashboard: people, who is in, and what waits for a decision. */
defineProps<{
    data: {
        headcount: number;
        onProbation: number;
        onLeaveToday: number;
        clockedInNow: number;
        pendingLeave: number;
        pendingCorrections: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
</script>

<template>
    <Card :title="t('hrm.widget.overview.title')" class="h-full" data-testid="hrm-overview-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('hrm.reports.index')">
                {{ t('hrm.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState v-if="data.headcount === 0" :title="t('hrm.widget.overview.empty')" compact>
            <template #icon><UsersRound /></template>
            <Button v-if="can('hrm.employees.create')" size="sm" variant="primary" :href="route('hrm.employees.create')">
                {{ t('hrm.employees.add') }}
            </Button>
        </EmptyState>

        <dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3">
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.headcount') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.headcount) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.clockedIn') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.clockedInNow) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.onLeave') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.onLeaveToday) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.probation') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.onProbation) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.pendingLeave') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.pendingLeave > 0 ? 'text-brand-text' : 'text-ink'">{{ format.number(data.pendingLeave) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('hrm.widget.overview.pendingCorrections') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.pendingCorrections > 0 ? 'text-brand-text' : 'text-ink'">{{ format.number(data.pendingCorrections) }}</dd>
            </div>
        </dl>
    </Card>
</template>

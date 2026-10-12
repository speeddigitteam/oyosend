<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** Workspace-wide Talent numbers for the dashboard: hiring, onboarding, probation, reviews and certificates. */
const props = defineProps<{
    data: {
        openOpenings: number;
        activeApplications: number;
        interviewsThisWeek: number;
        openOnboardingTasks: number;
        probationsEnding: number;
        reviewsAwaitingCalibration: number;
        certificatesExpiring: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();

const tiles = computed(() => [
    { key: 'openOpenings', value: props.data.openOpenings, attention: false },
    { key: 'activeApplications', value: props.data.activeApplications, attention: false },
    { key: 'interviewsThisWeek', value: props.data.interviewsThisWeek, attention: false },
    { key: 'openOnboardingTasks', value: props.data.openOnboardingTasks, attention: props.data.openOnboardingTasks > 0 },
    { key: 'probationsEnding', value: props.data.probationsEnding, attention: props.data.probationsEnding > 0 },
    { key: 'reviewsAwaitingCalibration', value: props.data.reviewsAwaitingCalibration, attention: props.data.reviewsAwaitingCalibration > 0 },
    { key: 'certificatesExpiring', value: props.data.certificatesExpiring, attention: props.data.certificatesExpiring > 0 },
]);
</script>

<template>
    <Card :title="t('talent.widget.overview.title')" class="h-full" data-testid="talent-overview-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('talent.analytics.index')">
                {{ t('talent.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-4">
            <div v-for="tile in tiles" :key="tile.key">
                <dt class="eyebrow">{{ t(`talent.widget.overview.${tile.key}`) }}</dt>
                <dd class="figure mt-2 text-2xl" :class="tile.attention ? 'text-brand-text' : 'text-ink'">{{ format.number(tile.value) }}</dd>
            </div>
        </dl>
    </Card>
</template>

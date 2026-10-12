<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { route } from '@/Core/lib/route';
import { shiftMonth, useHrmFormat } from './hrmFormat';

/** Previous / next month for a page that takes a `month` query (`Y-m`). */
const props = defineProps<{
    month: string;
    /** Route name of the page. */
    name: string;
    params?: Record<string, string>;
    /** Other query values to keep (a department filter). */
    query?: Record<string, string>;
}>();

const { t } = useI18n();
const hrm = useHrmFormat();

const label = computed(() => hrm.month(props.month));

function go(delta: number): void {
    router.get(route(props.name, props.params ?? {}), { ...(props.query ?? {}), month: shiftMonth(props.month, delta) }, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div class="flex items-center gap-1" role="group" :aria-label="t('hrm.common.month')">
        <Button size="sm" variant="secondary" icon @click="go(-1)">
            <template #icon><ChevronLeft class="size-4" aria-hidden="true" /></template>
            {{ t('hrm.common.previousMonth') }}
        </Button>
        <span class="min-w-32 px-2 text-center text-sm font-medium text-ink" aria-live="polite" data-testid="month-label">{{ label }}</span>
        <Button size="sm" variant="secondary" icon @click="go(1)">
            <template #icon><ChevronRight class="size-4" aria-hidden="true" /></template>
            {{ t('hrm.common.nextMonth') }}
        </Button>
    </div>
</template>

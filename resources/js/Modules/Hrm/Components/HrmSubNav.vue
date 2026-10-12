<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';

/**
 * Links between the pages of one HRM area (attendance, leave). The sidebar
 * reaches the area; this reaches its siblings, and only those the person may open.
 */
const props = defineProps<{
    area: 'attendance' | 'leave';
    current: 'day' | 'summary' | 'corrections' | 'requests' | 'approvals' | 'balances' | 'calendar';
}>();

const { t } = useI18n();
const { can } = useAccess();

const ITEMS = {
    attendance: [
        { value: 'day', name: 'hrm.attendance.index', permission: 'hrm.attendance.view' },
        { value: 'summary', name: 'hrm.attendance.summary', permission: 'hrm.attendance.view' },
        { value: 'corrections', name: 'hrm.attendance.corrections.index', permission: 'hrm.attendance.view' },
    ],
    leave: [
        { value: 'requests', name: 'hrm.leave.index', permission: 'hrm.leave.view' },
        { value: 'approvals', name: 'hrm.leave.approvals', permission: 'hrm.leave.approve' },
        { value: 'balances', name: 'hrm.leave.balances', permission: 'hrm.leave.view' },
        { value: 'calendar', name: 'hrm.leave.calendar', permission: 'hrm.leave.view' },
    ],
} as const;

const items = computed(() => ITEMS[props.area].filter((item) => can(item.permission)));
</script>

<template>
    <nav :aria-label="t('hrm.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`hrm.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>

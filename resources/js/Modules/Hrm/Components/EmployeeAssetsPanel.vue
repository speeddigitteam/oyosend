<script setup lang="ts">
import { Laptop, Plus, Undo2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import AssetDialog from './AssetDialog.vue';
import type { EmployeeAsset } from './types';

/** Company assets held by, or returned by, one employee. */
defineProps<{ employeeId: string; assets: EmployeeAsset[]; canManage: boolean; today: string }>();

const { t } = useI18n();
const format = useFormat();

const assignOpen = ref(false);
const returning = ref<EmployeeAsset | null>(null);
</script>

<template>
    <div>
        <div v-if="canManage" class="mb-4 flex justify-end">
            <Button size="sm" variant="secondary" @click="assignOpen = true">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.assets.assign') }}
            </Button>
        </div>

        <EmptyState v-if="assets.length === 0" :title="t('hrm.assets.empty')" compact>
            <template #icon><Laptop /></template>
        </EmptyState>
        <ul v-else class="divide-y divide-line">
            <li v-for="asset in assets" :key="asset.id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                        {{ asset.name }}
                        <Badge :tone="asset.isHeld ? 'ok' : 'neutral'" dot>{{ asset.isHeld ? t('hrm.assets.held') : t('hrm.assets.returned') }}</Badge>
                    </p>
                    <p class="text-xs text-ink-subtle">
                        <span v-if="asset.assetTag" class="font-mono">{{ asset.assetTag }}</span>
                        <span v-if="asset.assetTag && asset.category" aria-hidden="true"> - </span>
                        <span v-if="asset.category">{{ asset.category }}</span>
                    </p>
                    <p class="tabular mt-1 text-xs text-ink-muted">
                        {{ t('hrm.assets.assignedOnDate', { date: format.date(asset.assignedOn) }) }}
                        <template v-if="asset.returnedOn"> - {{ t('hrm.assets.returnedOnDate', { date: format.date(asset.returnedOn) }) }}</template>
                    </p>
                    <p v-if="asset.returnCondition" class="text-xs text-ink-muted">{{ t('hrm.assets.condition') }}: {{ asset.returnCondition }}</p>
                    <p v-if="asset.notes" class="text-xs text-ink-subtle">{{ asset.notes }}</p>
                </div>
                <Button v-if="canManage && asset.isHeld" size="sm" variant="ghost" @click="returning = asset">
                    <template #icon><Undo2 class="size-4" aria-hidden="true" /></template>
                    {{ t('hrm.assets.return') }}
                </Button>
            </li>
        </ul>

        <AssetDialog v-if="canManage" v-model:open="assignOpen" :employee-id="employeeId" :today="today" />
        <AssetDialog
            v-if="canManage"
            :open="returning !== null"
            :employee-id="employeeId"
            :today="today"
            :returning="returning"
            @update:open="!$event && (returning = null)"
        />
    </div>
</template>

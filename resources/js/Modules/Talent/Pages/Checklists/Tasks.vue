<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CircleCheck, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { ChecklistTask, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    tasks: Paginated<ChecklistTask>;
    today: string;
    hasProfile: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const busy = ref<string | null>(null);

function complete(task: ChecklistTask): void {
    busy.value = task.id;
    router.post(route('talent.tasks.complete', { task: task.id }), {}, { preserveScroll: true, onFinish: () => { busy.value = null; } });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.tasks.title')" :description="t('talent.tasks.description')" />

    <Card v-if="!hasProfile">
        <EmptyState :title="t('talent.me.noProfile')" :description="t('talent.me.noProfileBody')">
            <template #icon><UserRound /></template>
        </EmptyState>
    </Card>

    <Card v-else flush>
        <EmptyState v-if="tasks.data.length === 0" :title="t('talent.tasks.empty')" :description="t('talent.tasks.emptyBody')" class="p-5">
            <template #icon><CircleCheck /></template>
        </EmptyState>
        <ul v-else class="divide-y divide-line" data-testid="task-list">
            <li v-for="task in tasks.data" :key="task.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0">
                    <p class="text-sm text-ink">{{ task.title }}</p>
                    <p class="mt-0.5 text-xs text-ink-subtle">
                        <Link v-if="can('talent.onboarding.view')" :href="route('talent.checklists.show', { checklist: task.checklistId })" class="hover:text-brand-text">
                            {{ task.checklistName }}
                        </Link>
                        <template v-else>{{ task.checklistName }}</template>
                        <template v-if="task.subject"> - {{ task.subject.name }}</template>
                    </p>
                    <p v-if="task.dueOn" class="mt-0.5 text-xs" :class="task.dueOn < today ? 'text-danger' : 'text-ink-subtle'">
                        {{ t('talent.checklists.due', { date: format.date(task.dueOn) }) }}
                    </p>
                </div>
                <Button size="sm" variant="secondary" :loading="busy === task.id" @click="complete(task)">{{ t('talent.checklists.completeTask') }}</Button>
            </li>
        </ul>
        <Pagination :meta="tasks" />
    </Card>
</template>

<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Circle, LockKeyhole } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Logo from '@/Core/Components/Logo.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import ProgressBar from '../../Components/ProgressBar.vue';
import ProjectStatusBadge from '../../Components/ProjectStatusBadge.vue';
import type { ChecklistItem, ProjectFinancials, TaskPriority, TaskStatus } from '../../Components/types';

interface PortalTask {
    id: string;
    title: string;
    description?: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    startDate?: string | null;
    dueDate?: string | null;
    progress: number;
    checklist: ChecklistItem[];
    comments: Array<{ id: string; body: string; author?: string | null; createdAt: string }>;
}

interface PortalPagination {
    currentPage: number;
    perPage: number;
    total: number;
    lastPage: number;
    hasMorePages: boolean;
    previousPageUrl: string | null;
    nextPageUrl: string | null;
}

const props = defineProps<{
    project: { id: string; code: string; name: string; description?: string | null; status: 'planned' | 'active' | 'on_hold' | 'completed' | 'cancelled'; progress: number; startDate?: string | null; dueDate?: string | null };
    tasks: PortalTask[];
    tasksPagination: PortalPagination;
    client: { name: string; canComment: boolean };
    financials: ProjectFinancials | null;
    token: string;
}>();

const { t } = useI18n();
const format = useFormat();
const selected = ref<string | null>(null);
const form = useForm({ body: '' });
const pagination = computed(() => ({
    prev_page_url: props.tasksPagination.previousPageUrl,
    next_page_url: props.tasksPagination.nextPageUrl,
    from: props.tasks.length ? ((props.tasksPagination.currentPage - 1) * props.tasksPagination.perPage) + 1 : null,
    to: props.tasks.length ? ((props.tasksPagination.currentPage - 1) * props.tasksPagination.perPage) + props.tasks.length : null,
    total: props.tasksPagination.total,
}));

function selectComment(taskId: string): void { form.reset(); form.clearErrors(); selected.value = taskId; }
function cancelComment(): void { form.reset(); form.clearErrors(); selected.value = null; }
function comment(task: PortalTask): void { form.post(route('projects.portal.comments.store', { token: props.token, task: task.id }), { preserveScroll: true, onSuccess: cancelComment }); }
</script>

<template>
    <Head :title="project.name" />
    <div class="min-h-screen bg-canvas px-4 py-6 sm:px-6">
        <main class="mx-auto max-w-5xl">
            <header class="mb-6 flex items-center justify-between gap-4">
                <Logo class="h-8 w-auto" />
                <p class="text-sm text-ink-muted">{{ client.name }}</p>
            </header>

            <Card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="eyebrow">{{ t('projects.portal.title') }} &middot; {{ project.code }}</p>
                        <h1 class="mt-2 text-3xl text-ink">{{ project.name }}</h1>
                        <p v-if="project.description" class="mt-2 max-w-2xl text-ink-muted">{{ project.description }}</p>
                    </div>
                    <ProjectStatusBadge :value="project.status" />
                </div>
                <div class="mt-6 max-w-xl"><ProgressBar :value="project.progress" :label="t('projects.projects.progressLabel', { name: project.name })" /></div>
            </Card>

            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                <Card :title="t('projects.portal.sharedTasks')" flush>
                    <EmptyState v-if="tasks.length === 0" :title="t('projects.tasks.empty.title')" :description="t('projects.tasks.empty.body')" />
                    <div v-else class="divide-y divide-line">
                        <article v-for="task in tasks" :key="task.id" class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="font-semibold text-ink">{{ task.title }}</h2>
                                <div class="flex gap-2"><ProjectStatusBadge :value="task.status" /><ProjectStatusBadge :value="task.priority" /></div>
                            </div>
                            <p v-if="task.description" class="mt-2 whitespace-pre-wrap text-sm text-ink-muted">{{ task.description }}</p>
                            <ul v-if="task.checklist.length" class="mt-3 grid gap-1">
                                <li v-for="item in task.checklist" :key="item.id" class="flex items-center gap-2 text-sm text-ink-muted"><Check v-if="item.completed" class="size-4 text-ok" aria-hidden="true" /><Circle v-else class="size-4" aria-hidden="true" />{{ item.label }}</li>
                            </ul>
                            <div v-if="task.comments.length" class="mt-4 grid gap-2">
                                <div v-for="entry in task.comments" :key="entry.id" class="glass-well rounded-control px-3 py-2"><p class="text-sm text-ink">{{ entry.body }}</p><p class="mt-1 text-xs text-ink-subtle">{{ entry.author }}</p></div>
                            </div>
                            <Button v-if="client.canComment && selected !== task.id" size="sm" variant="ghost" class="mt-3" :disabled="form.processing" @click="selectComment(task.id)">{{ t('projects.portal.comment') }}</Button>
                            <form v-if="client.canComment && selected === task.id" class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" @submit.prevent="comment(task)">
                                <label class="min-w-0"><span class="sr-only">{{ t('projects.portal.comment') }}</span><textarea v-model="form.body" required maxlength="20000" class="control min-h-20 w-full px-3 py-2 text-sm" :aria-invalid="form.errors.body ? 'true' : undefined" :aria-describedby="form.errors.body ? `portal-comment-${task.id}-error` : undefined" /></label>
                                <p v-if="form.errors.body" :id="`portal-comment-${task.id}-error`" class="text-sm text-danger sm:col-start-1" role="alert">{{ form.errors.body }}</p>
                                <div class="flex justify-end gap-2 sm:col-start-2 sm:row-start-1"><Button variant="ghost" :disabled="form.processing" @click="cancelComment">{{ t('projects.common.cancel') }}</Button><Button type="submit" variant="primary" :loading="form.processing">{{ t('projects.portal.send') }}</Button></div>
                            </form>
                        </article>
                    </div>
                    <Pagination :meta="pagination" :disabled="form.processing" />
                </Card>

                <div class="grid content-start gap-3">
                    <Card v-if="financials" :title="t('projects.financials.title')">
                        <dl class="grid gap-3"><div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('projects.financials.budget') }}</dt><dd class="font-mono">{{ format.money(financials.budgetAmount, financials.currency) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('projects.financials.spent') }}</dt><dd class="font-mono">{{ format.money(financials.spentAmount, financials.currency) }}</dd></div><div class="flex justify-between gap-3 border-t border-line pt-3"><dt class="font-medium">{{ t('projects.financials.remaining') }}</dt><dd class="font-mono font-semibold">{{ format.money(financials.budgetRemainingAmount, financials.currency) }}</dd></div></dl>
                    </Card>
                    <Card><p class="flex items-start gap-2 text-sm text-ink-muted"><LockKeyhole class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('projects.portal.accessRestricted') }}</p></Card>
                </div>
            </div>
        </main>
    </div>
</template>

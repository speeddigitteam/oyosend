<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { ProjectTaskOption, TaskOptionsPagination, TaskOptionsResponse } from './types';

const props = withDefaults(defineProps<{
    projectId: string;
    error?: string;
    selectedTask?: ProjectTaskOption | null;
}>(), { error: undefined, selectedTask: null });

const model = defineModel<string>({ default: '' });
const { t } = useI18n();
const search = ref('');
const tasks = ref<ProjectTaskOption[]>([]);
const pagination = ref<TaskOptionsPagination | null>(null);
const resultCount = ref<number | null>(null);
const loading = ref(false);
const loadError = ref('');
let request: AbortController | null = null;
let debounce: number | undefined;
let suppressNextSearch = false;

const options = computed(() => [
    { value: '', label: t('projects.common.notSet') },
    ...tasks.value.map((task) => ({ value: task.id, label: task.name })),
]);
const hasMore = computed(() => !loadError.value && pagination.value?.hasMorePages === true);
const empty = computed(() => !loading.value && !loadError.value && props.projectId !== '' && resultCount.value === 0);

function unique(items: ProjectTaskOption[]): ProjectTaskOption[] {
    return [...new Map(items.map((task) => [task.id, task])).values()];
}

function retainedTask(): ProjectTaskOption | null {
    const loaded = tasks.value.find((task) => task.id === model.value);
    if (loaded?.projectId === props.projectId) return loaded;
    if (props.selectedTask?.id === model.value && props.selectedTask.projectId === props.projectId) return props.selectedTask;
    return null;
}

async function load(page = 1, append = false): Promise<void> {
    if (!props.projectId) return;

    request?.abort();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    loadError.value = '';
    if (!append) {
        pagination.value = null;
        resultCount.value = null;
    }

    const query: Record<string, string | number> = { project_id: props.projectId, page };
    const term = search.value.trim();
    if (term) query.search = term;

    try {
        const response = await fetch(route('projects.task-options.index', query), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error(t('projects.taskOptions.loadError'));

        const body = (await response.json()) as TaskOptionsResponse;
        if (request !== controller) return;

        const current = retainedTask();
        tasks.value = unique([
            ...(current ? [current] : []),
            ...(append ? tasks.value : []),
            ...(Array.isArray(body.tasks) ? body.tasks : []),
        ]);
        pagination.value = body.pagination;
        resultCount.value = body.pagination.total;
    } catch (reason: unknown) {
        if (!controller.signal.aborted && request === controller) {
            loadError.value = reason instanceof Error && reason.message
                ? reason.message
                : t('projects.taskOptions.loadError');
        }
    } finally {
        if (request === controller) {
            loading.value = false;
            request = null;
        }
    }
}

function loadMore(): void {
    if (!loading.value && hasMore.value) void load((pagination.value?.currentPage ?? 1) + 1, true);
}

function retry(): void {
    void load(1, false);
}

watch(() => props.projectId, (projectId, previousProjectId) => {
    window.clearTimeout(debounce);
    request?.abort();
    request = null;
    loading.value = false;
    loadError.value = '';
    pagination.value = null;
    resultCount.value = null;

    if (search.value !== '') {
        suppressNextSearch = true;
        search.value = '';
    }

    const selectedMatches = props.selectedTask?.id === model.value && props.selectedTask.projectId === projectId;
    if (previousProjectId !== undefined && previousProjectId !== projectId && !selectedMatches) model.value = '';
    tasks.value = selectedMatches && props.selectedTask ? [props.selectedTask] : [];

    if (projectId) void load(1, false);
}, { immediate: true });

watch(search, () => {
    if (suppressNextSearch) {
        suppressNextSearch = false;
        return;
    }

    window.clearTimeout(debounce);
    if (!props.projectId) return;
    debounce = window.setTimeout(() => { void load(1, false); }, 300);
});

onBeforeUnmount(() => {
    window.clearTimeout(debounce);
    request?.abort();
});
</script>

<template>
    <div class="grid gap-2 sm:col-span-2" :aria-busy="loading ? 'true' : undefined">
        <Input
            v-if="projectId"
            v-model="search"
            type="search"
            :label="t('projects.taskOptions.search')"
            :placeholder="t('projects.tasks.search')"
            autocomplete="off"
            maxlength="100"
        />
        <Select
            v-model="model"
            :options="options"
            :label="t('projects.fields.task')"
            :error="error"
            :disabled="!projectId || (loading && tasks.length === 0)"
        />
        <p v-if="!projectId" class="text-xs text-ink-muted" role="status">{{ t('projects.tasks.chooseProject') }}</p>
        <p v-else-if="loading" class="text-xs text-ink-muted" role="status" aria-live="polite">{{ t('projects.taskOptions.loading') }}</p>
        <div v-else-if="loadError" class="flex flex-wrap items-center gap-2" role="alert">
            <p class="text-xs text-danger">{{ loadError }}</p>
            <Button size="sm" variant="ghost" @click="retry">{{ t('projects.taskOptions.retry') }}</Button>
        </div>
        <p v-else-if="empty" class="text-xs text-ink-muted" role="status">{{ t('projects.taskOptions.empty') }}</p>
        <Button v-if="hasMore" class="justify-self-start" size="sm" variant="ghost" :disabled="loading" :loading="loading" @click="loadMore">
            {{ t('projects.taskOptions.loadMore') }}
        </Button>
    </div>
</template>

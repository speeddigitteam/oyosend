<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Download, FileUp } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { sizeParts } from '../../Components/socialFormat';
import type { ImportRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    imports: ImportRow[];
    columns: string[];
    maxRows: number;
    maxBytes: number;
    canSchedule: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm<{ file: File | null; mode: 'draft' | 'schedule' }>({ file: null, mode: 'draft' });

const modeOptions = computed(() => [
    { value: 'draft', label: t('social.imports.mode.draft') },
    ...(props.canSchedule ? [{ value: 'schedule', label: t('social.imports.mode.schedule') }] : []),
]);
const required = new Set(['body', 'accounts']);

const limitText = computed(() => {
    const { unit, value } = sizeParts(props.maxBytes);

    return t('social.imports.limits', {
        rows: format.number(props.maxRows),
        size: t(`social.units.${unit}`, { count: format.number(value) }),
    });
});

function choose(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post(route('social.imports.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.imports.title')" :description="t('social.imports.description')" />

    <!-- Full width: the upload box beside the column list, the history across the row below. The upload fields lay out
         by the box's own width (container queries); the upload and template buttons sit in the box's footer. -->
    <div class="grid gap-3 lg:grid-cols-3">
        <Card class="lg:col-span-2" :title="t('social.imports.upload')" :description="limitText">
            <form id="social-import" class="@container" novalidate @submit.prevent="submit">
                <div class="grid gap-4 @2xl:grid-cols-2">
                    <Input
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        :label="t('social.imports.file')"
                        :hint="t('social.imports.fileHint')"
                        :error="form.errors.file"
                        @change="choose"
                    />
                    <div class="grid content-start gap-2">
                        <Select v-model="form.mode" :options="modeOptions" :label="t('social.imports.modeLabel')" :error="form.errors.mode" />
                        <p class="text-[0.8125rem] text-ink-muted">{{ t(`social.imports.modeHint.${form.mode}`) }}</p>
                    </div>
                </div>
            </form>
            <template #footer>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a
                        :href="route('social.imports.template')"
                        class="inline-flex h-[2.375rem] items-center justify-center gap-2 rounded-control border border-line-strong bg-control px-4 text-sm font-medium whitespace-nowrap text-ink hover:bg-control-hover"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('social.imports.template') }}
                    </a>
                    <Button type="submit" form="social-import" variant="primary" :loading="form.processing" :disabled="form.file === null">
                        <template #icon><FileUp class="size-4" aria-hidden="true" /></template>
                        {{ t('social.imports.submit') }}
                    </Button>
                </div>
            </template>
        </Card>

        <Card :title="t('social.imports.columnsTitle')" :description="t('social.imports.columnsHint')">
            <ul class="grid gap-1.5 text-[0.8125rem]">
                <li v-for="column in columns" :key="column" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                    <code class="min-w-0 font-mono text-xs wrap-break-word text-ink">{{ column }}</code>
                    <Badge v-if="required.has(column)" tone="info">{{ t('social.imports.required') }}</Badge>
                </li>
            </ul>
        </Card>

        <Card class="lg:col-span-3" :title="t('social.imports.history')" flush>
            <ul v-if="imports.length > 0" class="divide-y divide-line" data-testid="imports">
                <li v-for="item in imports" :key="item.id" class="px-5 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="min-w-0 text-sm font-medium break-all text-ink">{{ item.fileName }}</span>
                        <Badge :tone="item.mode === 'schedule' ? 'brand' : 'neutral'">{{ t(`social.imports.mode.${item.mode}`) }}</Badge>
                        <span class="tabular ml-auto text-xs text-ink-subtle">{{ item.createdAt ? format.date(item.createdAt, 'datetime') : '' }}</span>
                    </div>
                    <p class="mt-1 text-[0.8125rem] text-ink-muted">
                        {{ t('social.imports.result', {
                            created: format.number(item.createdRows),
                            failed: format.number(item.failedRows),
                            total: format.number(item.totalRows),
                        }) }}
                    </p>
                    <details v-if="item.errors.length > 0" class="mt-2 text-[0.8125rem]">
                        <summary class="cursor-pointer text-danger">{{ t('social.imports.errors', { count: format.number(item.errors.length) }) }}</summary>
                        <ul class="mt-2 grid gap-1">
                            <li v-for="(error, index) in item.errors" :key="index" class="wrap-break-word text-ink-muted">
                                <span class="tabular mr-1 text-ink-subtle">{{ t('social.imports.line', { line: format.number(error.line) }) }}</span>{{ error.message }}
                            </li>
                        </ul>
                    </details>
                </li>
            </ul>
            <EmptyState v-else :title="t('social.imports.empty')" :description="t('social.imports.emptyBody')" compact>
                <template #icon><FileUp /></template>
            </EmptyState>
        </Card>
    </div>
</template>

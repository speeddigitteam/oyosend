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
import type { ImportRow, ListOption } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    imports: ImportRow[];
    lists: ListOption[];
    columns: string[];
    consentMethods: string[];
    maxRows: number;
    maxBytes: number;
    canCreateContacts: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm<{ file: File | null; list_id: string }>({ file: null, list_id: props.lists[0]?.id ?? '' });

const listOptions = computed(() => props.lists.map((item) => ({ value: item.id, label: item.name })));
const megabytes = computed(() => Math.max(1, Math.round(props.maxBytes / 1048576)));
const required = new Set(['email']);

function choose(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post(route('email_marketing.imports.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('file'),
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.imports.title')" :description="t('emailMarketing.imports.description')" />

    <div class="grid gap-3 lg:grid-cols-3">
        <Card class="lg:col-span-2" :title="t('emailMarketing.imports.upload')" :description="t('emailMarketing.imports.limits', { rows: format.number(maxRows), size: format.number(megabytes) })">
            <form class="grid max-w-xl gap-4" novalidate @submit.prevent="submit">
                <Select v-model="form.list_id" :options="listOptions" :label="t('emailMarketing.fields.list')" :error="form.errors.list_id" />
                <Input
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    :label="t('emailMarketing.imports.file')"
                    :hint="t('emailMarketing.imports.fileHint')"
                    :error="form.errors.file"
                    @change="choose"
                />
                <p class="text-[0.8125rem] text-ink-muted">{{ t('emailMarketing.imports.consentNote') }}</p>
                <p v-if="!canCreateContacts" class="text-[0.8125rem] text-warn">{{ t('emailMarketing.imports.noContactRights') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" variant="primary" :loading="form.processing" :disabled="form.file === null || form.list_id === ''">
                        <template #icon><FileUp class="size-4" aria-hidden="true" /></template>
                        {{ t('emailMarketing.imports.submit') }}
                    </Button>
                    <Button :href="route('email_marketing.imports.template')">
                        <template #icon><Download class="size-4" aria-hidden="true" /></template>
                        {{ t('emailMarketing.imports.template') }}
                    </Button>
                </div>
            </form>
        </Card>

        <Card :title="t('emailMarketing.imports.columnsTitle')" :description="t('emailMarketing.imports.columnsHint')">
            <ul class="grid gap-1.5 text-[0.8125rem]">
                <li v-for="column in columns" :key="column" class="flex items-center gap-2">
                    <code class="font-mono text-xs text-ink">{{ column }}</code>
                    <Badge v-if="required.has(column)" tone="info">{{ t('emailMarketing.imports.required') }}</Badge>
                </li>
                <li class="flex items-center gap-2"><code class="font-mono text-xs text-ink">prop_name</code><span class="text-ink-subtle">{{ t('emailMarketing.imports.propertyColumns') }}</span></li>
            </ul>
            <p class="mt-3 text-xs text-ink-subtle">{{ t('emailMarketing.imports.methods', { methods: consentMethods.join(', ') }) }}</p>
        </Card>
    </div>

    <Card class="mt-3" :title="t('emailMarketing.imports.history')" flush>
        <ul v-if="imports.length > 0" class="divide-y divide-line" data-testid="imports">
            <li v-for="item in imports" :key="item.id" class="px-5 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="min-w-0 text-sm font-medium break-all text-ink">{{ item.fileName }}</span>
                    <Badge tone="neutral">{{ item.listName }}</Badge>
                    <span class="tabular ml-auto text-xs text-ink-subtle">{{ format.date(item.createdAt, 'datetime') }}</span>
                </div>
                <p class="mt-1 text-[0.8125rem] text-ink-muted">
                    {{ t('emailMarketing.imports.result', {
                        subscribed: format.number(item.subscribedRows),
                        pending: format.number(item.pendingRows),
                        skipped: format.number(item.skippedRows),
                        failed: format.number(item.failedRows),
                        total: format.number(item.totalRows),
                    }) }}
                </p>
                <details v-if="item.errors.length > 0" class="mt-2 text-[0.8125rem]">
                    <summary class="cursor-pointer text-danger">{{ t('emailMarketing.imports.errors', { count: format.number(item.errors.length) }) }}</summary>
                    <ul class="mt-2 grid gap-1">
                        <li v-for="(error, index) in item.errors" :key="index" class="break-words text-ink-muted">
                            <span class="tabular mr-1 text-ink-subtle">{{ t('emailMarketing.imports.line', { line: format.number(error.line) }) }}</span>{{ error.message }}
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
        <EmptyState v-else :title="t('emailMarketing.imports.empty')" :description="t('emailMarketing.imports.emptyBody')" compact>
            <template #icon><FileUp /></template>
        </EmptyState>
    </Card>
</template>

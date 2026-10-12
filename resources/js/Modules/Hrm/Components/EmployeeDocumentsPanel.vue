<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Download, FileText, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import DocumentDialog from './DocumentDialog.vue';
import { useHrmFormat } from './hrmFormat';
import type { EmployeeDocument } from './types';

/** Private documents of one employee. Files download as attachments through the server. */
const props = defineProps<{ employeeId: string; documents: EmployeeDocument[]; canManage: boolean }>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const uploadOpen = ref(false);
const removing = ref<EmployeeDocument | null>(null);
const busy = ref(false);

function downloadUrl(document: EmployeeDocument): string {
    return route('hrm.employees.documents.download', { employee: props.employeeId, document: document.id });
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('hrm.employees.documents.destroy', { employee: props.employeeId, document: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; removing.value = null; },
    });
}
</script>

<template>
    <div>
        <div v-if="canManage" class="mb-4 flex justify-end">
            <Button size="sm" variant="secondary" @click="uploadOpen = true">
                <template #icon><Upload class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.documents.upload') }}
            </Button>
        </div>

        <EmptyState v-if="documents.length === 0" :title="t('hrm.documents.empty')" compact>
            <template #icon><FileText /></template>
        </EmptyState>
        <ul v-else class="divide-y divide-line">
            <li v-for="document in documents" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ document.title }}</p>
                    <p class="text-xs text-ink-subtle">
                        {{ t(`hrm.documents.types.${document.type}`) }}
                        <span aria-hidden="true">-</span>
                        <span class="tabular">{{ hrm.size(document.size) }}</span>
                        <template v-if="document.expiresOn">
                            <span aria-hidden="true">-</span>
                            {{ t('hrm.documents.expiresOn', { date: format.date(document.expiresOn) }) }}
                        </template>
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <a
                        :href="downloadUrl(document)"
                        class="inline-flex h-8 items-center gap-1.5 rounded-control px-3 text-[0.8125rem] font-medium text-ink-muted hover:bg-hover hover:text-ink"
                        :aria-label="t('hrm.documents.download', { name: document.title })"
                        data-testid="document-download"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('hrm.common.download') }}
                    </a>
                    <Button v-if="canManage" size="sm" variant="ghost" @click="removing = document">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.common.remove') }}
                    </Button>
                </div>
            </li>
        </ul>

        <DocumentDialog v-if="canManage" v-model:open="uploadOpen" :employee-id="employeeId" />
        <ConfirmDialog
            :open="removing !== null"
            :title="t('hrm.documents.removeTitle')"
            :description="t('hrm.documents.removeBody', { name: removing?.title ?? '' })"
            :confirm-label="t('hrm.common.remove')"
            :loading="busy"
            @update:open="!$event && (removing = null)"
            @confirm="remove"
        />
    </div>
</template>

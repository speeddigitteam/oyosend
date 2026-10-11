<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Download, FileUp, Paperclip, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { ProjectAttachment } from './types';

const props = defineProps<{ projectId: string; taskId?: string | null; attachments: ProjectAttachment[]; canUpload: boolean }>();
const { t } = useI18n();
const format = useFormat();
const fileInput = ref<HTMLInputElement | null>(null);
const removing = ref<ProjectAttachment | null>(null);
const deleting = ref(false);
const removeError = ref('');
const form = useForm<{ file: File | null; task_id: string | null }>({ file: null, task_id: props.taskId ?? null });

function choose(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    form.task_id = props.taskId ?? null;
    form.post(route('projects.attachments.store', { project: props.projectId }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { form.reset(); if (fileInput.value) fileInput.value.value = ''; },
    });
}

function remove(): void {
    if (!removing.value || deleting.value) return;
    deleting.value = true;
    removeError.value = '';
    router.delete(route('projects.attachments.destroy', { project: props.projectId, attachment: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => { removing.value = null; },
        onError: (errors) => { removeError.value = errors.attachment ?? errors.status ?? Object.values(errors)[0] ?? ''; },
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <section>
        <div v-if="canUpload" class="mb-3 flex flex-wrap items-end gap-2 rounded-control border border-line bg-control/40 p-3">
            <label class="min-w-0 flex-1 text-xs font-medium text-ink"><span class="mb-1 block">{{ t('projects.attachments.file') }}</span><input ref="fileInput" type="file" class="block w-full text-sm text-ink file:mr-3 file:rounded-control file:border-0 file:bg-hover file:px-3 file:py-2 file:text-sm file:text-ink" @change="choose" /></label>
            <Button size="sm" :disabled="!form.file" :loading="form.processing" @click="upload"><FileUp class="size-4" aria-hidden="true" />{{ t('projects.attachments.upload') }}</Button>
            <p v-if="form.errors.file" class="w-full text-xs text-danger" role="alert">{{ form.errors.file }}</p>
        </div>
        <EmptyState v-if="attachments.length === 0" :title="t('projects.attachments.empty')" compact><template #icon><Paperclip /></template></EmptyState>
        <ul v-else class="divide-y divide-line rounded-control border border-line">
            <li v-for="attachment in attachments" :key="attachment.id" class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0"><p class="truncate text-sm font-medium text-ink">{{ attachment.name }}</p><p class="text-xs text-ink-subtle">{{ format.number(attachment.size / 1024, { maximumFractionDigits: 1 }) }} KB<template v-if="attachment.uploader"> · {{ attachment.uploader }}</template></p></div>
                <div class="flex gap-1"><a class="inline-flex size-8 items-center justify-center rounded-control text-ink-muted hover:bg-hover hover:text-ink" :href="route('projects.attachments.download', { project: projectId, attachment: attachment.id })" :aria-label="t('projects.attachments.downloadFor', { name: attachment.name })"><Download class="size-4" aria-hidden="true" /></a><Button v-if="attachment.deletable" icon size="sm" variant="ghost" :aria-label="t('projects.attachments.deleteFor', { name: attachment.name })" @click="removeError = ''; removing = attachment"><Trash2 class="size-4" aria-hidden="true" /></Button></div>
            </li>
        </ul>
    </section>
    <ConfirmDialog :open="removing !== null" :title="t('projects.attachments.delete')" :description="removing ? t('projects.attachments.deleteBody', { name: removing.name }) : undefined" :confirm-label="t('projects.attachments.delete')" :loading="deleting" @update:open="!$event && !deleting && (removing = null)" @confirm="remove"><p v-if="removeError" class="text-sm text-danger" role="alert">{{ removeError }}</p></ConfirmDialog>
</template>

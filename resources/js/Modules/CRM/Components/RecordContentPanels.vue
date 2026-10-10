<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Download, FileText, MessageSquareText, Paperclip, Tags, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { RecordContent } from './types';

type CustomValue = string | number | boolean | null;
type Attachment = RecordContent['attachments'][number];

const props = defineProps<{ kind: 'lead' | 'deal' | 'contact' | 'company'; recordId: string; routeId?: string; content: RecordContent; editable: boolean }>();
const { t } = useI18n();
const format = useFormat();
const note = useForm({ body: '', pinned: false });
const upload = useForm<{ file: File | null }>({ file: null });
const tags = useForm({ tags: props.content.tags.map((item) => item.id) });
const custom = useForm({ values: {} as Record<string, CustomValue> });
const fieldValues = reactive<Record<string, CustomValue>>(Object.fromEntries(
    props.content.customFields.map((field) => [field.key, field.value == null ? null : field.value as CustomValue]),
));
const deleting = ref<Attachment | null>(null);
const deleteBusy = ref(false);
const routeTarget = computed(() => {
    if (props.kind === 'lead') return { prefix: 'crm.leads', params: { lead: props.routeId ?? props.recordId } };
    if (props.kind === 'deal') return { prefix: 'crm.deals', params: { deal: props.routeId ?? props.recordId } };

    return { prefix: 'crm.sales.customers', params: { customerProfile: props.routeId ?? props.recordId } };
});

function addNote(): void {
    note.post(route(`${routeTarget.value.prefix}.notes.store`, routeTarget.value.params), { preserveScroll: true, onSuccess: () => note.reset() });
}
function saveTags(): void {
    tags.put(route(`${routeTarget.value.prefix}.tags.update`, routeTarget.value.params), { preserveScroll: true });
}
function toggleTag(id: string, checked: boolean): void {
    tags.tags = checked ? [...new Set([...tags.tags, id])] : tags.tags.filter((item) => item !== id);
}
function saveCustomFields(): void {
    custom.values = { ...fieldValues };
    custom.put(route(`${routeTarget.value.prefix}.custom-values.update`, routeTarget.value.params), { preserveScroll: true });
}
function chooseFile(event: Event): void {
    upload.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function sendFile(): void {
    upload.post(route(`${routeTarget.value.prefix}.attachments.store`, routeTarget.value.params), { preserveScroll: true, forceFormData: true, onSuccess: () => upload.reset() });
}
function downloadUrl(id: string): string {
    return route(`${routeTarget.value.prefix}.attachments.download`, { ...routeTarget.value.params, attachment: id });
}
function removeAttachment(): void {
    if (!deleting.value) return;
    deleteBusy.value = true;
    router.delete(route(`${routeTarget.value.prefix}.attachments.destroy`, { ...routeTarget.value.params, attachment: deleting.value.id }), {
        preserveScroll: true,
        onFinish: () => { deleteBusy.value = false; deleting.value = null; },
    });
}
</script>

<template>
    <div class="grid gap-3 lg:grid-cols-2">
        <Card :title="t('crm.content.notes')" flush>
            <form v-if="editable" class="border-b border-line px-5 pb-4" @submit.prevent="addNote">
                <Textarea v-model="note.body" :label="t('crm.content.addNote')" :placeholder="t('crm.content.notePlaceholder')" :error="note.errors.body" required />
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3"><Checkbox v-model="note.pinned" :label="t('crm.content.pinned')" /><Button type="submit" size="sm" variant="primary" :loading="note.processing">{{ t('crm.content.addNote') }}</Button></div>
            </form>
            <EmptyState v-if="content.notes.length === 0" :title="t('crm.content.noNotes')" compact><template #icon><MessageSquareText /></template></EmptyState>
            <ol v-else class="divide-y divide-line"><li v-for="item in content.notes" :key="item.id" class="px-5 py-3"><p class="whitespace-pre-wrap text-sm text-ink">{{ item.body }}</p><p class="mt-1 text-xs text-ink-subtle">{{ item.author ? t('crm.content.addedBy', { date: format.date(item.createdAt), name: item.author }) : t('crm.content.addedOn', { date: format.date(item.createdAt) }) }}</p></li></ol>
        </Card>

        <div class="grid gap-3">
            <Card :title="t('crm.content.tags')">
                <form v-if="editable && content.tagOptions.length" class="grid gap-3" @submit.prevent="saveTags">
                    <div class="flex flex-wrap gap-x-5 gap-y-3">
                        <Checkbox v-for="item in content.tagOptions" :key="item.id" :model-value="tags.tags.includes(item.id)" :label="item.name" @update:model-value="toggleTag(item.id, $event)" />
                    </div>
                    <p v-if="tags.errors.tags" class="text-xs text-danger">{{ tags.errors.tags }}</p>
                    <Button type="submit" size="sm" variant="primary" class="justify-self-start" :loading="tags.processing">{{ t('crm.content.saveTags') }}</Button>
                </form>
                <div v-else-if="content.tags.length" class="flex flex-wrap gap-2"><Badge v-for="item in content.tags" :key="item.id" tone="brand">{{ item.name }}</Badge></div>
                <EmptyState v-else :title="t('crm.content.noTags')" compact><template #icon><Tags /></template></EmptyState>
            </Card>

            <Card :title="t('crm.content.customFields')">
                <form v-if="editable && content.customFields.length" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveCustomFields">
                    <template v-for="field in content.customFields" :key="field.id">
                        <Checkbox v-if="field.type === 'boolean'" :model-value="fieldValues[field.key] === true" :label="field.name" @update:model-value="fieldValues[field.key] = $event" />
                        <Select v-else-if="field.type === 'select'" :model-value="typeof fieldValues[field.key] === 'string' ? fieldValues[field.key] as string : ''" :options="(field.options ?? []).map((value) => ({ value, label: value }))" :label="field.name" :error="custom.errors[`values.${field.key}`]" :required="field.required" @update:model-value="fieldValues[field.key] = $event ?? null" />
                        <Input v-else :model-value="fieldValues[field.key] == null ? '' : String(fieldValues[field.key])" :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'" :label="field.name" :error="custom.errors[`values.${field.key}`]" :required="field.required" @update:model-value="fieldValues[field.key] = $event ?? null" />
                    </template>
                    <Button type="submit" size="sm" variant="primary" class="justify-self-start sm:col-span-2" :loading="custom.processing">{{ t('crm.content.saveCustomFields') }}</Button>
                </form>
                <dl v-else-if="content.customFields.length" class="grid gap-3 sm:grid-cols-2"><div v-for="field in content.customFields" :key="field.id"><dt class="eyebrow">{{ field.name }}</dt><dd class="mt-1 text-sm text-ink">{{ field.value ?? t('crm.common.notSet') }}</dd></div></dl>
                <EmptyState v-else :title="t('crm.content.noCustomFields')" compact><template #icon><FileText /></template></EmptyState>
            </Card>

            <Card :title="t('crm.content.attachments')" flush>
                <form v-if="editable" class="flex flex-wrap items-end gap-2 border-b border-line px-5 pb-4" @submit.prevent="sendFile"><Input type="file" :label="t('crm.content.upload')" :error="upload.errors.file" class="min-w-52 flex-1" @change="chooseFile" /><Button type="submit" size="sm" :disabled="!upload.file" :loading="upload.processing">{{ t('crm.content.upload') }}</Button></form>
                <EmptyState v-if="content.attachments.length === 0" :title="t('crm.content.noAttachments')" compact><template #icon><Paperclip /></template></EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="file in content.attachments" :key="file.id" class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0"><p class="truncate text-sm text-ink">{{ file.name }}</p><p class="tabular text-xs text-ink-subtle">{{ format.number(file.size) }}</p></div>
                        <div class="flex shrink-0 gap-1">
                            <Button icon size="sm" variant="ghost" :href="downloadUrl(file.id)" :aria-label="t('crm.content.download', { name: file.name })"><template #icon><Download class="size-4" aria-hidden="true" /></template></Button>
                            <Button v-if="editable" icon size="sm" variant="ghost" :aria-label="t('crm.content.removeAttachment', { name: file.name })" @click="deleting = file"><template #icon><Trash2 class="size-4 text-danger" aria-hidden="true" /></template></Button>
                        </div>
                    </li>
                </ul>
            </Card>
        </div>
    </div>

    <ConfirmDialog :open="deleting !== null" :title="t('crm.content.removeAttachmentTitle')" :description="deleting?.name" :confirm-label="t('crm.content.removeAttachmentConfirm')" :loading="deleteBusy" @update:open="!$event && (deleting = null)" @confirm="removeAttachment" />
</template>

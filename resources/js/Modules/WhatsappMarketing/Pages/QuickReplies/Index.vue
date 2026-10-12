<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import FormDialog from '../../Components/FormDialog.vue';
import type { QuickReplyRow } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    quickReplies: QuickReplyRow[];
    placeholders: string[];
    canManage: boolean;
}>();

const { t } = useI18n();

const shownPlaceholders = computed(() => props.placeholders.map((name) => '{' + '{' + name + '}' + '}'));

const dialog = ref(false);
const editing = ref<QuickReplyRow | null>(null);
const form = useForm({ shortcut: '', title: '', body: '', is_active: true });
const confirmOpen = ref(false);
const removing = ref<QuickReplyRow | null>(null);
const deleting = ref(false);

function create(): void {
    editing.value = null;
    form.defaults({ shortcut: '', title: '', body: '', is_active: true });
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function edit(reply: QuickReplyRow): void {
    editing.value = reply;
    form.defaults({ shortcut: reply.shortcut, title: reply.title, body: reply.body, is_active: reply.isActive });
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (dialog.value = false) };

    if (editing.value) {
        form.put(route('whatsapp_marketing.quick-replies.update', { quickReply: editing.value.id }), options);
    } else {
        form.post(route('whatsapp_marketing.quick-replies.store'), options);
    }
}

function askDelete(reply: QuickReplyRow): void {
    removing.value = reply;
    confirmOpen.value = true;
}

function destroy(): void {
    if (!removing.value) {
        return;
    }

    deleting.value = true;
    router.delete(route('whatsapp_marketing.quick-replies.destroy', { quickReply: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (deleting.value = false),
    });
}

const columns = computed<Column[]>(() => [
    { key: 'shortcut', label: t('whatsapp.quickReplies.columns.shortcut') },
    { key: 'title', label: t('whatsapp.quickReplies.columns.title') },
    { key: 'body', label: t('whatsapp.quickReplies.columns.body'), hideable: true },
    { key: 'active', label: t('whatsapp.quickReplies.columns.active') },
    ...(props.canManage ? [{ key: 'actions', label: t('whatsapp.common.actions') }] : []),
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.quickReplies.title')" :description="t('whatsapp.quickReplies.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="create">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.quickReplies.add') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="quickReplies" />

    <Card flush>
        <DataTable :columns="columns" :rows="quickReplies" row-key="id" :caption="t('whatsapp.quickReplies.caption')" storage-key="whatsapp-quick-replies">
            <template #cell-shortcut="{ row }"><span class="font-mono text-sm text-ink wrap-anywhere">{{ row.shortcut }}</span></template>
            <template #cell-title="{ row }"><span class="text-ink wrap-anywhere">{{ row.title }}</span></template>
            <template #cell-body="{ row }"><p class="line-clamp-2 max-w-md whitespace-pre-wrap text-ink-muted wrap-anywhere">{{ row.body }}</p></template>
            <template #cell-active="{ row }">
                <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('whatsapp.common.active') : t('whatsapp.common.inactive') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Button size="sm" variant="ghost" @click="edit(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="askDelete(row)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.common.delete') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('whatsapp.quickReplies.empty')" :description="t('whatsapp.quickReplies.emptyBody')">
                    <template #icon><Zap /></template>
                    <Button v-if="canManage" variant="primary" @click="create">{{ t('whatsapp.quickReplies.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Card class="mt-3" :title="t('whatsapp.quickReplies.placeholdersTitle')" :description="t('whatsapp.quickReplies.placeholdersBody')">
        <ul class="flex flex-wrap gap-2" :aria-label="t('whatsapp.quickReplies.placeholdersTitle')">
            <li v-for="placeholder in shownPlaceholders" :key="placeholder" class="rounded-chip bg-hover px-2 py-0.5 font-mono text-xs text-ink-muted">
                {{ placeholder }}
            </li>
        </ul>
    </Card>

    <template v-if="canManage">
        <FormDialog
            v-model:open="dialog"
            :title="editing ? t('whatsapp.quickReplies.editTitle') : t('whatsapp.quickReplies.createTitle')"
            :submit-label="t('whatsapp.common.save')"
            :processing="form.processing"
            @submit="submit"
        >
            <Input v-model="form.shortcut" :label="t('whatsapp.quickReplies.shortcut')" :hint="t('whatsapp.quickReplies.shortcutHint')" :error="form.errors.shortcut" mono maxlength="40" />
            <Input v-model="form.title" :label="t('whatsapp.quickReplies.titleField')" :error="form.errors.title" maxlength="120" />
            <Textarea v-model="form.body" :label="t('whatsapp.quickReplies.body')" :hint="t('whatsapp.quickReplies.bodyHint')" :error="form.errors.body" :rows="5" maxlength="1000" />
            <Checkbox v-model="form.is_active" :label="t('whatsapp.quickReplies.isActive')" :description="t('whatsapp.quickReplies.isActiveHint')" />
        </FormDialog>
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('whatsapp.quickReplies.deleteTitle', { shortcut: removing?.shortcut ?? '' })"
            :description="t('whatsapp.quickReplies.deleteBody')"
            :confirm-label="t('whatsapp.common.delete')"
            :loading="deleting"
            @confirm="destroy"
        />
    </template>
</template>

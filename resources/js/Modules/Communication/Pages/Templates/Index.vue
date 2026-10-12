<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { sendJson } from '@/Modules/Automation/lib/http';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import type { TemplateRow, Variable } from '../../Components/types';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    templates: TemplateRow[];
    variables: Variable[];
    channels: string[];
    locales: string[];
    purposes: string[];
    canManage: boolean;
}>();

interface Preview {
    subject: string;
    body: string;
    unknown: string[];
}

const { t } = useI18n();
const { label } = useCommunicationLabels();

const formOpen = ref(false);
const editing = ref<TemplateRow | null>(null);
const removing = ref<TemplateRow | null>(null);
const removePending = ref(false);
const preview = ref<Preview | null>(null);
const previewError = ref(false);
const previewing = ref(false);

const form = useForm({
    slug: '',
    name: '',
    channel: 'email',
    locale: 'en',
    purpose: 'transactional',
    subject: '',
    body: '',
    is_active: true,
});

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('communication.templates.name') },
    { key: 'channel', label: t('communication.templates.channel') },
    { key: 'locale', label: t('communication.templates.locale') },
    { key: 'purpose', label: t('communication.templates.purpose'), hideable: true },
    { key: 'status', label: t('communication.common.status') },
    { key: 'actions', label: t('communication.common.actions'), srOnly: true, align: 'right' },
]);

const channelOptions = computed(() => props.channels.map((value) => ({ value, label: label('channel', value) })));
const localeOptions = computed(() => props.locales.map((value) => ({ value, label: label('locale', value) })));
const purposeOptions = computed(() => props.purposes.map((value) => ({ value, label: label('purpose', value) })));
const tokens = computed(() => props.variables.map((variable) => ({ ...variable, token: `{{ ${variable.key} }}` })));
const showsSubject = computed(() => form.channel === 'email');
const example = '{{ contact.first_name }}';

function openForm(row: TemplateRow | null): void {
    editing.value = row;
    form.defaults({
        slug: row?.slug ?? '',
        name: row?.name ?? '',
        channel: row?.channel ?? 'email',
        locale: row?.locale ?? 'en',
        purpose: row?.purpose ?? 'transactional',
        subject: row?.subject ?? '',
        body: row?.body ?? '',
        is_active: row?.isActive ?? true,
    }).reset();
    form.clearErrors();
    preview.value = null;
    previewError.value = false;
    formOpen.value = true;
}

function insertToken(token: string): void {
    form.body = form.body === '' ? token : `${form.body}${form.body.endsWith(' ') || form.body.endsWith('\n') ? '' : ' '}${token}`;
}

async function runPreview(): Promise<void> {
    if (form.body.trim() === '') {
        return;
    }

    previewing.value = true;
    previewError.value = false;

    const response = await sendJson<Preview>('POST', route('communication.templates.preview'), {
        subject: showsSubject.value ? form.subject : null,
        body: form.body,
    });

    previewing.value = false;

    if (response.ok && response.data) {
        preview.value = response.data;
    } else {
        preview.value = null;
        previewError.value = true;
    }
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    };

    form.transform((data) => ({ ...data, subject: data.channel === 'email' && data.subject.trim() !== '' ? data.subject : null }));

    if (editing.value) {
        form.put(route('communication.templates.update', { template: editing.value.id }), options);
    } else {
        form.post(route('communication.templates.store'), options);
    }
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('communication.templates.destroy', { template: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
        },
        onFinish: () => {
            removePending.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.templates.title')" :description="t('communication.templates.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('communication.templates.add') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="templates" />

    <Card flush>
        <DataTable :columns="columns" :rows="templates" row-key="id" :caption="t('communication.templates.caption')">
            <template #cell-name="{ row }">
                <span class="text-ink">{{ row.name }}</span>
                <span class="block text-xs text-ink-subtle">
                    <span class="font-mono">{{ row.slug }}</span>
                    <template v-if="row.channel === 'email'"> - {{ row.subject ?? t('communication.templates.subjectMissing') }}</template>
                </span>
            </template>
            <template #cell-channel="{ row }">{{ label('channel', row.channel) }}</template>
            <template #cell-locale="{ row }">{{ label('locale', row.locale) }}</template>
            <template #cell-purpose="{ row }"><Badge tone="neutral">{{ label('purpose', row.purpose) }}</Badge></template>
            <template #cell-status="{ row }">
                <Badge :tone="row.isActive ? 'ok' : 'neutral'" dot>{{ row.isActive ? t('communication.common.active') : t('communication.common.inactive') }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-end gap-1">
                    <Button icon size="sm" variant="ghost" :aria-label="t('communication.templates.editFor', { name: row.name })" @click="openForm(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                    </Button>
                    <Button icon size="sm" variant="ghost" :aria-label="t('communication.templates.deleteFor', { name: row.name })" @click="removing = row">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('communication.templates.empty')" :description="t('communication.templates.emptyBody')">
                    <template #icon><FileText /></template>
                    <Button v-if="canManage" variant="primary" @click="openForm(null)">{{ t('communication.templates.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="formOpen" size="lg" :title="t(editing ? 'communication.templates.edit' : 'communication.templates.add')" :persistent="form.processing">
        <form id="communication-template-form" class="grid gap-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.name" :label="t('communication.templates.name')" :error="form.errors.name" required maxlength="160" />
                <Input
                    v-model="form.slug"
                    :label="t('communication.templates.slug')"
                    :hint="t('communication.templates.slugHint')"
                    :error="form.errors.slug"
                    required
                    mono
                    maxlength="80"
                />
                <Select v-model="form.channel" :options="channelOptions" :label="t('communication.templates.channel')" :error="form.errors.channel" />
                <Select v-model="form.locale" :options="localeOptions" :label="t('communication.templates.locale')" :error="form.errors.locale" />
                <Select v-model="form.purpose" :options="purposeOptions" :label="t('communication.templates.purpose')" :error="form.errors.purpose" />
            </div>
            <Input v-if="showsSubject" v-model="form.subject" :label="t('communication.templates.subject')" :error="form.errors.subject" optional maxlength="200" />
            <Textarea
                v-model="form.body"
                :rows="8"
                :label="t('communication.templates.body')"
                :hint="t('communication.templates.bodyHint', { placeholder: example })"
                :error="form.errors.body"
                required
                maxlength="5000"
            />
            <div>
                <p class="text-xs text-ink-subtle">{{ t('communication.templates.placeholdersHint') }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5" role="group" :aria-label="t('communication.templates.placeholders')">
                    <Button
                        v-for="variable in tokens"
                        :key="variable.key"
                        size="sm"
                        variant="ghost"
                        :title="variable.label"
                        :aria-label="t('communication.templates.insert', { name: variable.label })"
                        @click="insertToken(variable.token)"
                    >
                        <span class="font-mono text-xs">{{ variable.key }}</span>
                    </Button>
                </div>
            </div>
            <Checkbox v-model="form.is_active" :label="t('communication.templates.active')" :description="t('communication.templates.activeHint')" />

            <div class="rounded-card bg-hover px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="eyebrow">{{ t('communication.templates.preview') }}</p>
                        <p class="text-xs text-ink-subtle">{{ t('communication.templates.previewHint') }}</p>
                    </div>
                    <Button size="sm" variant="secondary" :loading="previewing" :disabled="form.body.trim() === ''" @click="runPreview">
                        {{ t('communication.templates.preview') }}
                    </Button>
                </div>
                <p v-if="previewError" class="mt-3 text-sm text-danger" role="alert">{{ t('communication.templates.previewFailed') }}</p>
                <div v-if="preview" class="mt-3 grid gap-2" aria-live="polite">
                    <p v-if="preview.unknown.length > 0" class="text-sm text-warn" role="status">
                        {{ t('communication.templates.previewUnknown', { names: preview.unknown.join(', ') }) }}
                    </p>
                    <p v-if="showsSubject && preview.subject" class="text-sm font-semibold text-ink wrap-anywhere">{{ preview.subject }}</p>
                    <p class="text-sm whitespace-pre-wrap wrap-anywhere text-ink">{{ preview.body }}</p>
                </div>
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="formOpen = false">{{ t('communication.common.cancel') }}</Button>
            <Button form="communication-template-form" type="submit" variant="primary" :loading="form.processing">{{ t('communication.common.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('communication.templates.deleteTitle')"
        :description="t('communication.templates.deleteBody', { name: removing?.name ?? '' })"
        :confirm-label="t('communication.common.delete')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removing = null)"
        @confirm="remove"
    />
</template>

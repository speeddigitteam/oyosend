<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Network, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { EndpointRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ endpoints: EndpointRow[] }>();

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();

const editing = ref<EndpointRow | null>(null);
const formOpen = ref(false);
const removing = ref<EndpointRow | null>(null);
const removeOpen = computed({ get: () => removing.value !== null, set: (value: boolean) => { if (!value) removing.value = null; } });
const removeWorking = ref(false);

const form = useForm({ name: '', url: '', secret: '', is_active: true });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('automation.webhooks.name') },
    { key: 'active', label: t('automation.common.status') },
    { key: 'secret', label: t('automation.webhooks.secret'), hideable: true },
    { key: 'lastCalled', label: t('automation.webhooks.lastCalled'), hideable: true },
    { key: 'actions', label: t('automation.common.actions'), align: 'right', srOnly: true },
]);

function openForm(endpoint: EndpointRow | null): void {
    editing.value = endpoint;
    form.defaults({ name: endpoint?.name ?? '', url: endpoint?.url ?? '', secret: '', is_active: endpoint?.active ?? true });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function submit(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        url: data.url.trim(),
        secret: data.secret === '' ? null : data.secret,
        is_active: data.is_active,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(route('automation.webhooks.update', { endpoint: editing.value.id }), options);
    } else {
        form.post(route('automation.webhooks.store'), options);
    }
}

function remove(): void {
    const endpoint = removing.value;

    if (!endpoint) {
        return;
    }

    removeWorking.value = true;
    router.delete(route('automation.webhooks.destroy', { endpoint: endpoint.id }), {
        preserveScroll: true,
        onError: (errors) => {
            toasts.error(errors.endpoint ?? t('automation.webhooks.inUse'));
        },
        onFinish: () => {
            removeWorking.value = false;
            removing.value = null;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('automation.eyebrow')" :title="t('automation.webhooks.title')" :description="t('automation.webhooks.description')">
        <template #actions>
            <Button variant="primary" type="button" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('automation.webhooks.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <DataTable :columns="columns" :rows="endpoints" row-key="id" :caption="t('automation.webhooks.caption')">
            <template #cell-name="{ row }">
                <span class="font-medium text-ink">{{ row.name }}</span>
                <span class="block max-w-md truncate font-mono text-xs text-ink-subtle">{{ row.url }}</span>
            </template>
            <template #cell-active="{ row }">
                <Badge :tone="row.active ? 'ok' : 'neutral'" dot>{{ row.active ? t('automation.webhooks.active') : t('automation.editor.webhook.inactive') }}</Badge>
            </template>
            <template #cell-secret="{ row }">
                <span class="text-ink-muted">{{ row.hasSecret ? t('automation.webhooks.secretSet') : t('automation.webhooks.secretNotSet') }}</span>
            </template>
            <template #cell-lastCalled="{ row }">
                <span v-if="row.lastCalledAt" class="text-ink-muted">
                    {{ format.relative(row.lastCalledAt) }}
                    <span v-if="row.lastStatusCode !== null" class="tabular">({{ format.number(row.lastStatusCode) }})</span>
                </span>
                <span v-else class="text-ink-subtle">{{ t('automation.webhooks.never') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" type="button" @click="openForm(row)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.webhooks.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" type="button" @click="removing = row">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('automation.webhooks.remove') }}
                    </Button>
                </div>
            </template>
            <template #empty>
                <EmptyState :title="t('automation.webhooks.empty')" :description="t('automation.webhooks.emptyBody')" compact>
                    <template #icon><Network /></template>
                    <Button size="sm" variant="primary" type="button" @click="openForm(null)">{{ t('automation.webhooks.add') }}</Button>
                </EmptyState>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="formOpen" :title="editing ? t('automation.webhooks.formEdit') : t('automation.webhooks.formAdd')" :persistent="form.processing">
        <form id="webhook-endpoint" class="space-y-4" @submit.prevent="submit">
            <Input v-model="form.name" :label="t('automation.webhooks.name')" :error="form.errors.name" maxlength="120" required />
            <Input
                v-model="form.url"
                type="url"
                :label="t('automation.webhooks.url')"
                :hint="t('automation.webhooks.urlHint')"
                :error="form.errors.url"
                maxlength="2048"
                mono
                required
            />
            <Input
                v-model="form.secret"
                type="password"
                autocomplete="new-password"
                :label="t('automation.webhooks.secret')"
                :hint="editing?.hasSecret ? t('automation.webhooks.secretKeep') : t('automation.webhooks.secretHint')"
                :error="form.errors.secret"
                optional
                maxlength="255"
            />
            <Checkbox v-model="form.is_active" :label="t('automation.webhooks.active')" />
        </form>
        <template #footer>
            <Button variant="ghost" type="button" :disabled="form.processing" @click="formOpen = false">{{ t('automation.common.cancel') }}</Button>
            <Button variant="primary" type="submit" form="webhook-endpoint" :loading="form.processing" :disabled="form.name.trim() === '' || form.url.trim() === ''">
                {{ t('automation.common.save') }}
            </Button>
        </template>
    </Modal>

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('automation.webhooks.removeTitle')"
        :description="t('automation.webhooks.removeBody')"
        :confirm-label="t('automation.webhooks.remove')"
        :loading="removeWorking"
        @confirm="remove"
    />
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import SupportNav from '../../Components/SupportNav.vue';

defineOptions({ layout: TenantLayout });

interface CannedRow {
    id: string;
    name: string;
    body: string;
    isActive: boolean;
    updatedAt: string | null;
}

const props = defineProps<{
    responses: Paginated<CannedRow>;
    filters: { search: string };
    placeholders: string[];
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const search = ref(props.filters.search);
const formOpen = ref(false);
const editing = ref<CannedRow | null>(null);
const removing = ref<CannedRow | null>(null);
const removePending = ref(false);

const form = useForm({ name: '', body: '', is_active: true });
const tokens = computed(() => props.placeholders.map((name) => ({ name, token: `{{ ${name} }}` })));

function find(): void {
    router.get(route('support.canned.index'), search.value === '' ? {} : { search: search.value }, { preserveState: true });
}

function openForm(row: CannedRow | null): void {
    editing.value = row;
    form.defaults({ name: row?.name ?? '', body: row?.body ?? '', is_active: row?.isActive ?? true }).reset();
    form.clearErrors();
    formOpen.value = true;
}

function insertToken(token: string): void {
    form.body = form.body === '' ? token : `${form.body}${form.body.endsWith(' ') || form.body.endsWith('\n') ? '' : ' '}${token}`;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(route('support.canned.update', { canned: editing.value.id }), options);
    } else {
        form.post(route('support.canned.store'), options);
    }
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('support.canned.destroy', { canned: removing.value.id }), {
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
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.canned.title')" :description="t('support.canned.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.canned.add') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="canned" />

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card>
                <form role="search" class="flex flex-wrap items-end gap-2" @submit.prevent="find">
                    <Input v-model="search" class="min-w-56 flex-1" :label="t('support.canned.search')" maxlength="100">
                        <template #leading><Search class="size-4" aria-hidden="true" /></template>
                    </Input>
                    <Button type="submit" size="sm">{{ t('support.filters.apply') }}</Button>
                </form>
            </Card>

            <Card v-if="responses.data.length === 0" flush>
                <EmptyState :title="t(filters.search ? 'support.canned.emptyFiltered' : 'support.canned.empty')" :description="filters.search ? undefined : t('support.canned.emptyBody')">
                    <template #icon><MessageSquareText /></template>
                </EmptyState>
            </Card>
            <ul v-else class="grid gap-3" :aria-label="t('support.canned.title')">
                <li v-for="response in responses.data" :key="response.id">
                    <Card :title="response.name">
                        <template v-if="canManage" #actions>
                            <Badge v-if="!response.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                            <Button icon size="sm" variant="ghost" :aria-label="t('support.canned.editFor', { name: response.name })" @click="openForm(response)">
                                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                            </Button>
                            <Button icon size="sm" variant="ghost" :aria-label="t('support.canned.deleteFor', { name: response.name })" @click="removing = response">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            </Button>
                        </template>
                        <p class="text-sm whitespace-pre-wrap wrap-anywhere text-ink">{{ response.body }}</p>
                        <p v-if="response.updatedAt" class="tabular mt-3 text-xs text-ink-subtle">{{ t('support.common.updated', { time: format.date(response.updatedAt, 'datetime') }) }}</p>
                    </Card>
                </li>
            </ul>
            <Pagination :meta="responses" />
        </div>

        <Card :title="t('support.canned.placeholders')" :description="t('support.canned.placeholdersHint')">
            <ul class="grid gap-2">
                <li v-for="item in tokens" :key="item.name" class="font-mono text-xs text-ink">{{ item.token }}</li>
            </ul>
        </Card>
    </div>

    <Modal v-model:open="formOpen" size="lg" :title="t(editing ? 'support.canned.edit' : 'support.canned.add')" :persistent="form.processing">
        <form id="support-canned-form" class="grid gap-4" @submit.prevent="save">
            <Input v-model="form.name" :label="t('support.fields.name')" :error="form.errors.name" required maxlength="120" />
            <Textarea v-model="form.body" :rows="8" :label="t('support.canned.body')" :error="form.errors.body" required maxlength="5000" />
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-ink-subtle">{{ t('support.canned.insert') }}</span>
                <Button v-for="item in tokens" :key="item.name" size="sm" variant="ghost" @click="insertToken(item.token)">{{ item.name }}</Button>
            </div>
            <Checkbox v-model="form.is_active" :label="t('support.canned.active')" :description="t('support.canned.activeHint')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="formOpen = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-canned-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('support.canned.deleteTitle')"
        :description="t('support.canned.deleteBody', { name: removing?.name ?? '' })"
        :confirm-label="t('support.common.delete')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removing = null)"
        @confirm="remove"
    />
</template>

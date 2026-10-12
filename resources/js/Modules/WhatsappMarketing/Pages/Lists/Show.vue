<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Search, Trash2, UserPlus, UsersRound, X } from 'lucide-vue-next';
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
import Pagination from '@/Core/Components/Pagination.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import FormDialog from '../../Components/FormDialog.vue';
import { safeRoute } from '../../Components/links';
import type { ContactMatch, Paginated } from '../../Components/types';
import { useWhatsappFormat } from '../../Components/useWhatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

interface MemberRow {
    id: string;
    contactId: string;
    name: string | null;
    phone: string | null;
    validPhone: boolean;
    canReceive: boolean;
    reason: string;
}

const props = defineProps<{
    list: { id: string; name: string; description: string | null; memberCount: number; cap: number };
    members: Paginated<MemberRow>;
    candidates: ContactMatch[];
    search: string;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
const { reason } = useWhatsappFormat();

const audienceHref = computed(() => (can('communication.consent.manage') || can('communication.audience.view') ? safeRoute('communication.audience.index') : null));

const query = ref(props.search);
const selected = ref<string[]>([]);
const adding = ref(false);
const addingAll = ref(false);
const allConfirm = ref(false);
const removing = ref<MemberRow | null>(null);
const removeConfirm = ref(false);
const removeBusy = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const edit = useForm({ name: props.list.name, description: props.list.description ?? '' });

function runSearch(): void {
    selected.value = [];
    router.get(route('whatsapp_marketing.lists.show', { list: props.list.id }), query.value.trim() === '' ? {} : { search: query.value.trim() }, {
        preserveState: true,
        preserveScroll: true,
        only: ['candidates', 'search'],
    });
}

function toggle(id: string, on: boolean): void {
    selected.value = on ? [...new Set([...selected.value, id])] : selected.value.filter((item) => item !== id);
}

function addSelected(): void {
    adding.value = true;
    router.post(route('whatsapp_marketing.lists.members.store', { list: props.list.id }), { contact_ids: selected.value }, {
        preserveScroll: true,
        onSuccess: () => (selected.value = []),
        onFinish: () => (adding.value = false),
    });
}

function addAll(): void {
    addingAll.value = true;
    router.post(route('whatsapp_marketing.lists.members.all', { list: props.list.id }), {}, {
        preserveScroll: true,
        onSuccess: () => (allConfirm.value = false),
        onFinish: () => (addingAll.value = false),
    });
}

function askRemove(member: MemberRow): void {
    removing.value = member;
    removeConfirm.value = true;
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    removeBusy.value = true;
    router.delete(route('whatsapp_marketing.lists.members.destroy', { list: props.list.id, member: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => (removeConfirm.value = false),
        onFinish: () => (removeBusy.value = false),
    });
}

function openEdit(): void {
    edit.defaults({ name: props.list.name, description: props.list.description ?? '' });
    edit.reset();
    edit.clearErrors();
    editOpen.value = true;
}

function saveEdit(): void {
    edit.transform((data) => ({ name: data.name.trim(), description: data.description.trim() || null })).put(
        route('whatsapp_marketing.lists.update', { list: props.list.id }),
        { preserveScroll: true, onSuccess: () => (editOpen.value = false) },
    );
}

function destroyList(): void {
    deleteBusy.value = true;
    router.delete(route('whatsapp_marketing.lists.destroy', { list: props.list.id }), { onFinish: () => (deleteBusy.value = false) });
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('whatsapp.lists.columns.contact') },
    { key: 'phone', label: t('whatsapp.lists.columns.phone') },
    { key: 'optIn', label: t('whatsapp.lists.columns.optIn') },
    ...(props.canManage ? [{ key: 'actions', label: t('whatsapp.common.actions') }] : []),
]);
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="list.name" :description="list.description ?? undefined">
        <template #actions>
            <Button variant="ghost" :href="route('whatsapp_marketing.lists.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.lists.back') }}
            </Button>
            <template v-if="canManage">
                <Button variant="secondary" @click="openEdit">
                    <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.common.edit') }}
                </Button>
                <Button variant="ghost" @click="deleteOpen = true">
                    <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.common.delete') }}
                </Button>
            </template>
        </template>
    </PageHeader>
    <WhatsappNav current="lists" />

    <p class="mb-3 rounded-card border border-line bg-hover px-4 py-3 text-sm text-ink-muted" data-testid="optin-note">
        {{ t('whatsapp.lists.optInNote') }}
        <Link v-if="audienceHref" :href="audienceHref" class="text-brand-text hover:underline">{{ t('whatsapp.lists.audienceLink') }}</Link>
    </p>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <Card flush :title="t('whatsapp.lists.membersTitle', { count: format.number(list.memberCount), cap: format.number(list.cap) })">
            <DataTable :columns="columns" :rows="members.data" row-key="id" :caption="t('whatsapp.lists.membersCaption')">
                <template #cell-name="{ row }"><span class="text-ink wrap-anywhere">{{ row.name ?? t('whatsapp.common.none') }}</span></template>
                <template #cell-phone="{ row }">
                    <span class="tabular text-ink-muted">{{ row.phone ?? t('whatsapp.common.none') }}</span>
                    <p v-if="!row.validPhone" class="text-xs text-warn">{{ t('whatsapp.lists.invalidPhone') }}</p>
                </template>
                <template #cell-optIn="{ row }">
                    <div class="grid gap-0.5">
                        <Badge :tone="row.canReceive ? 'ok' : 'warn'" dot>{{ row.canReceive ? t('whatsapp.lists.canReceive') : t('whatsapp.lists.cannotReceive') }}</Badge>
                        <span v-if="!row.canReceive" class="text-xs text-ink-muted">{{ reason(row.reason) }}</span>
                    </div>
                </template>
                <template #cell-actions="{ row }">
                    <Button size="sm" variant="ghost" @click="askRemove(row)">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.lists.remove') }}
                    </Button>
                </template>
                <template #empty>
                    <EmptyState :title="t('whatsapp.lists.membersEmpty')" :description="canManage ? t('whatsapp.lists.membersEmptyBody') : undefined">
                        <template #icon><UsersRound /></template>
                    </EmptyState>
                </template>
            </DataTable>
            <Pagination :meta="members" />
        </Card>

        <Card v-if="canManage" :title="t('whatsapp.lists.addTitle')" :description="t('whatsapp.lists.addDescription')">
            <form class="grid gap-3" role="search" @submit.prevent="runSearch">
                <Input v-model="query" :label="t('whatsapp.lists.searchContacts')" maxlength="100">
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
                <div><Button type="submit" size="sm" variant="secondary">{{ t('whatsapp.common.search') }}</Button></div>
            </form>

            <div class="mt-4 grid gap-2">
                <p v-if="search === ''" class="text-sm text-ink-subtle">{{ t('whatsapp.lists.searchHint') }}</p>
                <p v-else-if="candidates.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.lists.noCandidates') }}</p>
                <template v-else>
                    <ul class="grid gap-2" :aria-label="t('whatsapp.lists.candidates')">
                        <li v-for="candidate in candidates" :key="candidate.id">
                            <Checkbox
                                :model-value="selected.includes(candidate.id)"
                                :label="candidate.name"
                                :description="candidate.phone ?? undefined"
                                @update:model-value="toggle(candidate.id, $event)"
                            />
                            <p v-if="candidate.validPhone === false" class="ml-7 text-xs text-warn">{{ t('whatsapp.lists.invalidPhone') }}</p>
                        </li>
                    </ul>
                    <div>
                        <Button size="sm" variant="primary" :loading="adding" :disabled="selected.length === 0" @click="addSelected">
                            <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.lists.addSelected', { count: format.number(selected.length) }) }}
                        </Button>
                    </div>
                </template>
            </div>

            <template #footer>
                <Button size="sm" variant="ghost" @click="allConfirm = true">{{ t('whatsapp.lists.addAll') }}</Button>
            </template>
        </Card>
    </div>

    <template v-if="canManage">
        <ConfirmDialog
            v-model:open="allConfirm"
            tone="default"
            :title="t('whatsapp.lists.addAllTitle')"
            :description="t('whatsapp.lists.addAllBody', { cap: format.number(list.cap) })"
            :confirm-label="t('whatsapp.lists.addAllConfirm')"
            :loading="addingAll"
            @confirm="addAll"
        />
        <ConfirmDialog
            v-model:open="removeConfirm"
            :title="t('whatsapp.lists.removeTitle', { name: removing?.name ?? removing?.phone ?? '' })"
            :description="t('whatsapp.lists.removeBody')"
            :confirm-label="t('whatsapp.lists.remove')"
            :loading="removeBusy"
            @confirm="remove"
        />
        <ConfirmDialog
            v-model:open="deleteOpen"
            :title="t('whatsapp.lists.deleteTitle', { name: list.name })"
            :description="t('whatsapp.lists.deleteBody')"
            :confirm-label="t('whatsapp.common.delete')"
            :loading="deleteBusy"
            @confirm="destroyList"
        />
        <FormDialog v-model:open="editOpen" :title="t('whatsapp.lists.editTitle')" :submit-label="t('whatsapp.common.save')" :processing="edit.processing" @submit="saveEdit">
            <Input v-model="edit.name" :label="t('whatsapp.lists.name')" :error="edit.errors.name" maxlength="120" />
            <Textarea v-model="edit.description" :label="t('whatsapp.lists.descriptionField')" :error="edit.errors.description" optional :rows="3" maxlength="300" />
        </FormDialog>
    </template>
</template>

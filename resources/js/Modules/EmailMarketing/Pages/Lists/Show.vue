<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmailBadge from '../../Components/EmailBadge.vue';
import MemberTable from '../../Components/MemberTable.vue';
import SegmentDialog from '../../Components/SegmentDialog.vue';
import SignupPanel from '../../Components/SignupPanel.vue';
import type { ListRow, MemberRow, Paginated, SegmentRow, SignupSummary, SubscriptionStatus } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    list: ListRow;
    members: Paginated<MemberRow>;
    hiddenMembers?: number;
    segments: SegmentRow[];
    filters: { status: string | null; segment: string | null; q: string | null };
    statuses: SubscriptionStatus[];
    consentMethods: string[];
    segmentFields: string[];
    segmentOperators: Record<string, string[]>;
    signup?: SignupSummary | null;
    canManage: boolean;
    canManageSubscribers: boolean;
    canExport: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const status = ref(props.filters.status ?? 'all');
const segment = ref(props.filters.segment ?? '');
const search = ref(props.filters.q ?? '');
const filtered = computed(() => status.value !== 'all' || segment.value !== '' || search.value.trim() !== '');

const statusOptions = computed(() => [
    { value: 'all', label: t('emailMarketing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`emailMarketing.status.subscription.${value}`) })),
]);
const segmentOptions = computed(() => [{ value: '', label: t('emailMarketing.campaigns.wholeList') }, ...props.segments.map((item) => ({ value: item.id, label: item.name }))]);

function apply(): void {
    router.get(
        route('email_marketing.lists.show', { list: props.list.id }),
        { status: status.value === 'all' ? undefined : status.value, segment: segment.value || undefined, q: search.value.trim() || undefined },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    status.value = 'all';
    segment.value = '';
    search.value = '';
    apply();
}

// Add a subscriber: search the shared contact directory, then give evidence (or leave the member pending).
const adding = ref(false);
const contactTerm = ref('');
const contactResults = ref<{ id: string; name: string; email: string }[]>([]);
const searching = ref(false);
const add = useForm({ contact_id: '', consent_method: '', consent_evidence: '', consented_at: '' });
const methodOptions = computed(() => [
    { value: '', label: t('emailMarketing.members.noEvidence') },
    ...props.consentMethods.map((value) => ({ value, label: t(`emailMarketing.consentMethod.${value}`) })),
]);
const addInvalid = computed(
    () => add.contact_id === '' || (add.consent_method !== '' && add.consent_evidence.trim().length < 5) || (add.consent_method === '' && add.consent_evidence.trim() !== ''),
);

async function searchContacts(): Promise<void> {
    const term = contactTerm.value.trim();

    if (term.length < 2) {
        contactResults.value = [];

        return;
    }

    searching.value = true;

    try {
        const response = await fetch(`${route('email_marketing.subscribers.contacts')}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        contactResults.value = response.ok ? ((await response.json()) as { id: string; name: string; email: string }[]) : [];
    } catch {
        contactResults.value = [];
    } finally {
        searching.value = false;
    }
}

function submitAdd(): void {
    add.transform((data) => ({
        contact_id: data.contact_id,
        consent_method: data.consent_method === '' ? null : data.consent_method,
        consent_evidence: data.consent_evidence.trim() === '' ? null : data.consent_evidence.trim(),
        consented_at: data.consented_at === '' ? null : data.consented_at,
    })).post(route('email_marketing.subscribers.store', { list: props.list.id }), {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false;
            add.reset();
            contactResults.value = [];
            contactTerm.value = '';
        },
    });
}

// List details
const editing = ref(false);
const edit = useForm({ name: props.list.name, description: props.list.description ?? '' });

function saveList(): void {
    edit.transform((data) => ({ name: data.name.trim(), description: data.description.trim() === '' ? null : data.description.trim() })).put(
        route('email_marketing.lists.update', { list: props.list.id }),
        { preserveScroll: true, onSuccess: () => (editing.value = false) },
    );
}

const toggling = ref(false);

function setStatus(next: 'active' | 'archived'): void {
    toggling.value = true;
    router.put(
        route('email_marketing.lists.update', { list: props.list.id }),
        { name: props.list.name, description: props.list.description, status: next },
        { preserveScroll: true, onFinish: () => (toggling.value = false) },
    );
}

const deleting = ref(false);

function destroy(): void {
    router.delete(route('email_marketing.lists.destroy', { list: props.list.id }), { onFinish: () => (deleting.value = false) });
}

// Segments
const segmentOpen = ref(false);
const segmentEditing = ref<SegmentRow | null>(null);
const segmentDeleting = ref<SegmentRow | null>(null);

function openSegment(item: SegmentRow | null): void {
    segmentEditing.value = item;
    segmentOpen.value = true;
}

function removeSegment(): void {
    if (segmentDeleting.value === null) {
        return;
    }

    router.delete(route('email_marketing.segments.destroy', { segment: segmentDeleting.value.id }), {
        preserveScroll: true,
        onFinish: () => (segmentDeleting.value = null),
    });
}

const tiles = computed(() =>
    (['subscribed', 'pending', 'unsubscribed', 'bounced', 'complained'] as const).map((key) => ({ key, value: props.list.counts[key] })),
);
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.lists.title')" :title="list.name" :description="list.description ?? undefined">
        <template #actions>
            <EmailBadge kind="list" :value="list.status" />
            <Button v-if="canManageSubscribers && list.status === 'active'" variant="primary" @click="adding = true">{{ t('emailMarketing.members.add') }}</Button>
            <Button v-if="canExport" :href="route('email_marketing.lists.export', { list: list.id })">{{ t('emailMarketing.members.export') }}</Button>
            <Button v-if="canManage" @click="editing = true">{{ t('emailMarketing.common.edit') }}</Button>
            <Button v-if="canManage && list.status === 'active'" variant="ghost" :loading="toggling" @click="setStatus('archived')">{{ t('emailMarketing.lists.archive') }}</Button>
            <Button v-if="canManage && list.status === 'archived'" variant="ghost" :loading="toggling" @click="setStatus('active')">{{ t('emailMarketing.lists.restore') }}</Button>
            <Button v-if="canManage" variant="ghost" @click="deleting = true">{{ t('emailMarketing.common.delete') }}</Button>
        </template>
    </PageHeader>

    <dl class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-5" data-testid="list-counts">
        <div v-for="tile in tiles" :key="tile.key" class="glass rounded-card px-4 py-3">
            <dt class="eyebrow">{{ t(`emailMarketing.status.subscription.${tile.key}`) }}</dt>
            <dd class="figure mt-2 text-2xl text-ink">{{ format.number(tile.value) }}</dd>
        </div>
    </dl>

    <SignupPanel v-if="canManage && signup" :list-id="list.id" :list-active="list.status === 'active'" :signup="signup" />

    <Card :title="t('emailMarketing.segments.title')" :description="t('emailMarketing.segments.intro')" class="mb-3">
        <template v-if="canManage" #actions>
            <Button size="sm" @click="openSegment(null)">{{ t('emailMarketing.segments.add') }}</Button>
        </template>
        <p v-if="segments.length === 0" class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.segments.empty') }}</p>
        <ul v-else class="divide-y divide-line" data-testid="segments">
            <li v-for="item in segments" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                <span class="text-sm font-medium text-ink">{{ item.name }}</span>
                <span class="text-xs text-ink-subtle">
                    {{ t(`emailMarketing.segments.${item.match === 'all' ? 'matchAllShort' : 'matchAnyShort'}`, { count: item.rules.length }) }}
                </span>
                <span class="tabular ml-auto text-[0.8125rem] text-ink-muted">{{ t('emailMarketing.segments.size', { count: format.number(item.count ?? 0) }) }}</span>
                <span v-if="canManage" class="flex gap-1">
                    <Button size="sm" variant="ghost" @click="openSegment(item)">{{ t('emailMarketing.common.edit') }}</Button>
                    <Button size="sm" variant="ghost" @click="segmentDeleting = item">{{ t('emailMarketing.common.delete') }}</Button>
                </span>
            </li>
        </ul>
    </Card>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('emailMarketing.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('emailMarketing.fields.status')" class="w-full sm:w-44" />
            <Select v-if="segments.length > 0" v-model="segment" :options="segmentOptions" :label="t('emailMarketing.fields.segment')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('emailMarketing.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('emailMarketing.common.clearFilters') }}</Button>
        </form>
        <p v-if="(hiddenMembers ?? 0) > 0" class="border-b border-line px-5 py-3 text-[0.8125rem] text-ink-muted" data-testid="hidden-members">
            {{ t('emailMarketing.members.hidden', { count: format.number(hiddenMembers ?? 0) }) }}
        </p>
        <MemberTable :members="members.data" :consent-methods="consentMethods" :can-manage="canManageSubscribers" :filtered="filtered" />
        <Pagination :meta="members" />
    </Card>

    <Modal :open="adding" :title="t('emailMarketing.members.add')" :description="t('emailMarketing.members.addBody')" @update:open="adding = $event">
        <form id="add-member" class="grid gap-4" novalidate @submit.prevent="submitAdd">
            <div class="grid gap-2">
                <Input
                    v-model="contactTerm"
                    type="search"
                    :label="t('emailMarketing.members.findContact')"
                    :hint="t('emailMarketing.members.findContactHint')"
                    :error="add.errors.contact_id"
                    @input="searchContacts"
                />
                <ul v-if="contactResults.length > 0" class="grid gap-1" role="listbox" :aria-label="t('emailMarketing.members.findContact')">
                    <li v-for="contact in contactResults" :key="contact.id">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
                            <input v-model="add.contact_id" type="radio" name="contact" :value="contact.id" class="size-4" />
                            <span>{{ contact.name }} <span class="text-ink-subtle">{{ contact.email }}</span></span>
                        </label>
                    </li>
                </ul>
                <p v-else-if="contactTerm.trim().length >= 2 && !searching" class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.members.noContacts') }}</p>
            </div>
            <Select v-model="add.consent_method" :options="methodOptions" :label="t('emailMarketing.fields.consentMethod')" :error="add.errors.consent_method" />
            <Textarea
                v-if="add.consent_method !== ''"
                v-model="add.consent_evidence"
                :label="t('emailMarketing.fields.consentEvidence')"
                :hint="t('emailMarketing.members.evidenceHint')"
                maxlength="500"
                :error="add.errors.consent_evidence"
            />
            <Input v-if="add.consent_method !== ''" v-model="add.consented_at" type="date" :label="t('emailMarketing.fields.consentedAt')" optional :error="add.errors.consented_at" />
            <p v-if="add.consent_method === ''" class="text-[0.8125rem] text-ink-muted">{{ t('emailMarketing.members.pendingNote') }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" @click="adding = false">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button type="submit" form="add-member" variant="primary" :loading="add.processing" :disabled="addInvalid">{{ t('emailMarketing.members.add') }}</Button>
        </template>
    </Modal>

    <Modal :open="editing" :title="t('emailMarketing.lists.edit')" size="sm" @update:open="editing = $event">
        <form id="edit-list" class="grid gap-4" novalidate @submit.prevent="saveList">
            <Input v-model="edit.name" :label="t('emailMarketing.fields.name')" maxlength="120" required :error="edit.errors.name" />
            <Textarea v-model="edit.description" :label="t('emailMarketing.fields.description')" maxlength="500" optional :error="edit.errors.description" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="editing = false">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button type="submit" form="edit-list" variant="primary" :loading="edit.processing" :disabled="edit.name.trim() === ''">{{ t('emailMarketing.common.save') }}</Button>
        </template>
    </Modal>

    <SegmentDialog v-model:open="segmentOpen" :list-id="list.id" :segment="segmentEditing" :fields="segmentFields" :operators="segmentOperators" />

    <ConfirmDialog
        :open="deleting"
        :title="t('emailMarketing.lists.deleteTitle')"
        :description="t('emailMarketing.lists.deleteBody')"
        :confirm-label="t('emailMarketing.common.delete')"
        @update:open="deleting = $event"
        @confirm="destroy"
    />
    <ConfirmDialog
        :open="segmentDeleting !== null"
        :title="t('emailMarketing.segments.deleteTitle')"
        :description="t('emailMarketing.segments.deleteBody')"
        :confirm-label="t('emailMarketing.common.delete')"
        @update:open="segmentDeleting = $event ? segmentDeleting : null"
        @confirm="removeSegment"
    />
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import MemberTable from '../../Components/MemberTable.vue';
import type { ListOption, MemberRow, Paginated, SubscriptionStatus } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    members: Paginated<MemberRow>;
    filters: { status: string | null; list_id: string | null; q: string | null };
    statuses: SubscriptionStatus[];
    lists: ListOption[];
    consentMethods: string[];
    canManage: boolean;
}>();

const { t } = useI18n();

const status = ref(props.filters.status ?? 'all');
const list = ref(props.filters.list_id ?? '');
const search = ref(props.filters.q ?? '');
const filtered = computed(() => status.value !== 'all' || list.value !== '' || search.value.trim() !== '');

const statusOptions = computed(() => [
    { value: 'all', label: t('emailMarketing.common.all') },
    ...props.statuses.map((value) => ({ value, label: t(`emailMarketing.status.subscription.${value}`) })),
]);
const listOptions = computed(() => [{ value: '', label: t('emailMarketing.common.all') }, ...props.lists.map((item) => ({ value: item.id, label: item.name }))]);

function apply(): void {
    router.get(
        route('email_marketing.subscribers.index'),
        { status: status.value === 'all' ? undefined : status.value, list_id: list.value || undefined, q: search.value.trim() || undefined },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    status.value = 'all';
    list.value = '';
    search.value = '';
    apply();
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="t('emailMarketing.subscribers.title')" :description="t('emailMarketing.subscribers.description')" />

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('emailMarketing.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="status" :options="statusOptions" :label="t('emailMarketing.fields.status')" class="w-full sm:w-44" />
            <Select v-model="list" :options="listOptions" :label="t('emailMarketing.fields.list')" class="w-full sm:w-48" />
            <Button type="submit" size="sm">{{ t('emailMarketing.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('emailMarketing.common.clearFilters') }}</Button>
        </form>
        <MemberTable :members="members.data" :consent-methods="consentMethods" :can-manage="canManage" show-list :filtered="filtered" />
        <Pagination :meta="members" />
    </Card>
</template>

<script setup lang="ts">
import { Building2, SearchX, UsersRound } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import type { DirectoryOption } from './types';

/** What the people list says when a page has nobody on it, the same in the list and the grid. */
defineProps<{
    /** The search the server applied, cut short for display; empty when nothing was searched. */
    query: string;
    company: DirectoryOption | null;
    canCreate: boolean;
}>();

defineEmits<{ clearFilters: []; clearCompany: []; create: [] }>();

const { t } = useI18n();
</script>

<template>
    <EmptyState v-if="query !== ''" :title="t('contacts.list.noMatch.title', { query })" :description="t('contacts.list.noMatch.body')">
        <template #icon><SearchX /></template>
        <Button size="sm" @click="$emit('clearFilters')">
            {{ company ? t('contacts.list.clearFilters') : t('contacts.list.clearSearch') }}
        </Button>
    </EmptyState>
    <EmptyState
        v-else-if="company"
        :title="t('contacts.people.empty.companyTitle', { company: company.name })"
        :description="t('contacts.people.empty.companyBody')"
    >
        <template #icon><Building2 /></template>
        <Button v-if="canCreate" size="sm" @click="$emit('create')">{{ t('contacts.people.add') }}</Button>
        <Button size="sm" variant="ghost" @click="$emit('clearCompany')">{{ t('contacts.people.empty.showAll') }}</Button>
    </EmptyState>
    <EmptyState v-else :title="t('common.table.empty')">
        <template #icon><UsersRound /></template>
    </EmptyState>
</template>

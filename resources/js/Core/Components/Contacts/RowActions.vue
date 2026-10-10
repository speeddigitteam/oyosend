<script setup lang="ts">
import { Ellipsis, Eye, GitMerge, Pencil, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';

/**
 * The menu at the end of a directory row. Everyone who sees a record may open
 * its details; editing and deleting appear only where the server said this
 * person's permission reaches the record.
 */
defineProps<{
    /** Id of the record, so focus can come back to this button after a dialog. */
    recordId: string;
    /** Name of the record, for the button's label. */
    name: string;
    editable: boolean;
    deletable: boolean;
    /** Both permissions are required because merging changes this record and removes the duplicate. */
    canMerge?: boolean;
}>();

defineEmits<{ view: []; edit: []; duplicates: []; delete: [] }>();

const { t } = useI18n();
</script>

<template>
    <Dropdown width="w-56">
        <template #trigger>
            <Button icon size="sm" variant="ghost" :data-record-actions="recordId">
                <template #icon><Ellipsis class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.list.menu', { name }) }}
            </Button>
        </template>

        <DropdownItem @select="$emit('view')">
            <template #icon><Eye aria-hidden="true" /></template>
            {{ t('contacts.list.view') }}
        </DropdownItem>
        <DropdownItem v-if="editable" @select="$emit('edit')">
            <template #icon><Pencil aria-hidden="true" /></template>
            {{ t('common.actions.edit') }}
        </DropdownItem>
        <DropdownItem v-if="canMerge" @select="$emit('duplicates')">
            <template #icon><GitMerge aria-hidden="true" /></template>
            {{ t('contacts.people.merge.menu') }}
        </DropdownItem>
        <template v-if="deletable">
            <DropdownSeparator />
            <DropdownItem tone="danger" @select="$emit('delete')">
                <template #icon><Trash2 aria-hidden="true" /></template>
                {{ t('common.actions.delete') }}
            </DropdownItem>
        </template>
    </Dropdown>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { route } from '@/Core/lib/route';
import EmergencyContactDialog from './EmergencyContactDialog.vue';
import type { EmergencyContact } from './types';

/** Emergency contacts of one employee (up to ten, one primary). */
const props = defineProps<{ employeeId: string; contacts: EmergencyContact[]; canManage: boolean }>();

const { t } = useI18n();

const formOpen = ref(false);
const editing = ref<EmergencyContact | null>(null);
const removing = ref<EmergencyContact | null>(null);
const busy = ref(false);

function add(): void {
    editing.value = null;
    formOpen.value = true;
}

function edit(contact: EmergencyContact): void {
    editing.value = contact;
    formOpen.value = true;
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    busy.value = true;
    router.delete(route('hrm.employees.contacts.destroy', { employee: props.employeeId, contact: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; removing.value = null; },
    });
}
</script>

<template>
    <div>
        <div v-if="canManage" class="mb-4 flex justify-end">
            <Button size="sm" variant="secondary" @click="add">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('hrm.contacts.add') }}
            </Button>
        </div>

        <EmptyState v-if="contacts.length === 0" :title="t('hrm.contacts.empty')" compact />
        <ul v-else class="divide-y divide-line">
            <li v-for="contact in contacts" :key="contact.id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                        {{ contact.name }}
                        <Badge v-if="contact.isPrimary" tone="brand">{{ t('hrm.contacts.primaryBadge') }}</Badge>
                    </p>
                    <p class="text-sm text-ink-muted">{{ contact.relationship }}</p>
                    <p class="tabular mt-1 text-sm text-ink-muted">{{ contact.phone }}</p>
                    <p v-if="contact.email" class="text-sm text-ink-muted">{{ contact.email }}</p>
                    <p v-if="contact.address" class="text-sm text-ink-subtle">{{ contact.address }}</p>
                </div>
                <div v-if="canManage" class="flex gap-1">
                    <Button size="sm" variant="ghost" @click="edit(contact)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.common.edit') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="removing = contact">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('hrm.common.remove') }}
                    </Button>
                </div>
            </li>
        </ul>

        <EmergencyContactDialog v-if="canManage" v-model:open="formOpen" :employee-id="employeeId" :contact="editing" />
        <ConfirmDialog
            :open="removing !== null"
            :title="t('hrm.contacts.removeTitle')"
            :description="t('hrm.contacts.removeBody', { name: removing?.name ?? '' })"
            :confirm-label="t('hrm.common.remove')"
            :loading="busy"
            @update:open="!$event && (removing = null)"
            @confirm="remove"
        />
    </div>
</template>

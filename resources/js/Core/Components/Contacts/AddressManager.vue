<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import { route } from '@/Core/lib/route';
import AddressFormDialog from './AddressFormDialog.vue';
import { addressTitle } from './addresses';
import type { AddressParent, AddressRow } from './types';

/**
 * The dialogs and requests behind a list of addresses, so the company page and the contact details share them: the
 * form for adding and changing, the confirmation before deleting, and making one the default. A page holds it by `ref`
 * and calls `add`, `edit`, `makeDefault` or `remove`.
 */
const { t } = useI18n();

const parent = ref<AddressParent>({ kind: 'company', id: '', name: '' });
const target = ref<AddressRow | null>(null);
const formOpen = ref(false);

const deleteTarget = ref<AddressRow | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);
const deleteName = computed(() => (deleteTarget.value ? addressTitle(deleteTarget.value, t) : ''));

function add(to: AddressParent): void {
    parent.value = to;
    target.value = null;
    formOpen.value = true;
}

function edit(of: AddressParent, address: AddressRow): void {
    parent.value = of;
    target.value = address;
    formOpen.value = true;
}

function makeDefault(address: AddressRow): void {
    router.post(route('core.contacts.addresses.default', { address: address.id }), {}, { preserveScroll: true });
}

function remove(of: AddressParent, address: AddressRow): void {
    parent.value = of;
    deleteTarget.value = address;
    deleteError.value = null;
    deleteOpen.value = true;
}

function destroy(): void {
    if (!deleteTarget.value) return;

    deleting.value = true;
    deleteError.value = null;

    router.delete(route('core.contacts.addresses.destroy', { address: deleteTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

defineExpose({ add, edit, makeDefault, remove });
</script>

<template>
    <AddressFormDialog v-model:open="formOpen" :address="target" :parent="parent" />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('contacts.addresses.delete.title', { name: deleteName })"
        :description="t('contacts.addresses.delete.body', { parent: parent.name })"
        :confirm-label="t('contacts.addresses.delete.confirm')"
        :loading="deleting"
        @confirm="destroy"
    >
        <p v-if="deleteError" class="text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
    </ConfirmDialog>
</template>

<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import AddressList from './AddressList.vue';
import type { AddressRow } from './types';

/**
 * A contact's addresses in its details dialog. Adding and changing them is offered only when the server said the
 * viewer may change the contact; the dialogs themselves belong to the page (see AddressManager).
 */
defineProps<{
    addresses: AddressRow[];
    editable: boolean;
}>();

defineEmits<{ add: []; edit: [address: AddressRow]; makeDefault: [address: AddressRow]; delete: [address: AddressRow] }>();

const { t } = useI18n();
</script>

<template>
    <section class="mt-6 border-t border-line pt-4" aria-labelledby="contact-addresses-title" data-contact-addresses>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 id="contact-addresses-title" class="text-sm font-semibold text-ink">{{ t('contacts.addresses.title') }}</h3>
            <Button v-if="editable" size="sm" variant="secondary" data-contact-add-address @click="$emit('add')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('contacts.addresses.add') }}
            </Button>
        </div>

        <AddressList
            v-if="addresses.length"
            :addresses="addresses"
            compact
            @edit="(address) => $emit('edit', address)"
            @make-default="(address) => $emit('makeDefault', address)"
            @delete="(address) => $emit('delete', address)"
        />
        <p v-else class="text-sm text-ink-subtle">{{ t('contacts.addresses.none') }}</p>
    </section>
</template>

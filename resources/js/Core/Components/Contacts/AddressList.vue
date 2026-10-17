<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AddressCard from './AddressCard.vue';
import { groupAddresses } from './addresses';
import type { AddressRow } from './types';

/**
 * A company's or a contact's addresses, grouped by what they are for: invoice, then delivery, then other, the default
 * of each first. The cards' menus are passed up; the page that shows the list owns the dialogs.
 */
const props = defineProps<{
    addresses: AddressRow[];
    /** Narrower grid, for the contact details dialog. */
    compact?: boolean;
}>();

defineEmits<{ edit: [address: AddressRow]; makeDefault: [address: AddressRow]; delete: [address: AddressRow] }>();

const { t } = useI18n();

const groups = computed(() => groupAddresses(props.addresses));
</script>

<template>
    <div class="grid gap-4">
        <section v-for="group in groups" :key="group.type" :aria-labelledby="`address-group-${group.type}`" :data-address-group="group.type">
            <h3 :id="`address-group-${group.type}`" class="eyebrow mb-2">{{ t(`contacts.addresses.groups.${group.type}`) }}</h3>
            <ul :class="['grid gap-3', compact ? 'sm:grid-cols-2' : 'sm:grid-cols-2 xl:grid-cols-3']">
                <li v-for="address in group.addresses" :key="address.id" class="min-w-0">
                    <AddressCard
                        :address="address"
                        @edit="$emit('edit', address)"
                        @make-default="$emit('makeDefault', address)"
                        @delete="$emit('delete', address)"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>

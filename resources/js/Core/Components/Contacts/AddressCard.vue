<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Ellipsis, Mail, MapPin, Pencil, Phone, Star, Trash2, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import { countryName } from '@/Core/lib/countries';
import { addressTitle, cityLine } from './addresses';
import { mailHref, telHref } from './links';
import type { AddressRow } from './types';

/**
 * One typed address as a card: what it is for, the default badge, the place as an envelope has it, and the person,
 * phone and email that go with it. The menu is offered only when the server said the viewer may change its parent.
 */
const props = defineProps<{
    address: AddressRow;
}>();

defineEmits<{ edit: []; makeDefault: []; delete: [] }>();

const { t } = useI18n();
const page = usePage();

const title = computed(() => addressTitle(props.address, t));
const city = computed(() => cityLine(props.address));
const country = computed(() => countryName(props.address.country, page.props.app.locale) || null);
const hasPlace = computed(() => Boolean(props.address.address || city.value || country.value));

const LINK = 'min-w-0 truncate rounded-chip underline-offset-4 transition-colors hover:text-brand-text hover:underline';
</script>

<template>
    <article class="glass relative flex h-full min-w-0 flex-col rounded-card p-4" :data-address-card="address.id">
        <div class="flex min-w-0 flex-wrap items-center gap-2 pr-8">
            <span class="eyebrow">{{ t(`contacts.addresses.types.${address.type}`) }}</span>
            <Badge v-if="address.isDefault" tone="brand">
                <Star class="size-3" aria-hidden="true" />
                {{ t('contacts.addresses.default') }}
            </Badge>
        </div>

        <h4 class="mt-1 min-w-0 truncate text-[0.9375rem] font-semibold text-ink" :title="title">{{ title }}</h4>

        <ul class="mt-2 grid gap-1.5 text-[0.8125rem] text-ink-muted">
            <li v-if="hasPlace" class="flex min-w-0 items-start gap-2">
                <MapPin class="mt-0.5 size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                <span class="sr-only">{{ t('contacts.addresses.fields.address') }}:</span>
                <span class="min-w-0 wrap-break-word whitespace-pre-line text-ink">
                    <template v-if="address.address">{{ address.address }}<br /></template>
                    <template v-if="city">{{ city }}<br v-if="country" /></template>
                    <template v-if="country">{{ country }}</template>
                </span>
            </li>
            <li v-if="address.name" class="flex min-w-0 items-center gap-2">
                <UserRound class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                <span class="truncate" :title="address.name">{{ t('contacts.addresses.attention', { name: address.name }) }}</span>
            </li>
            <li v-if="address.phone" class="flex min-w-0 items-center gap-2">
                <Phone class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                <span class="sr-only">{{ t('contacts.addresses.fields.phone') }}:</span>
                <a v-if="telHref(address.phone)" :href="telHref(address.phone)" :class="[LINK, 'tabular']">{{ address.phone }}</a>
                <span v-else class="tabular truncate">{{ address.phone }}</span>
            </li>
            <li v-if="address.email" class="flex min-w-0 items-center gap-2">
                <Mail class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                <span class="sr-only">{{ t('contacts.addresses.fields.email') }}:</span>
                <a :href="mailHref(address.email)" :title="address.email" :class="LINK">{{ address.email }}</a>
            </li>
        </ul>

        <div v-if="address.editable" class="absolute top-2.5 right-2.5">
            <Dropdown width="w-52">
                <template #trigger>
                    <Button icon size="sm" variant="ghost" :data-address-actions="address.id">
                        <template #icon><Ellipsis class="size-4" aria-hidden="true" /></template>
                        {{ t('contacts.addresses.menu', { name: title }) }}
                    </Button>
                </template>

                <DropdownItem @select="$emit('edit')">
                    <template #icon><Pencil aria-hidden="true" /></template>
                    {{ t('common.actions.edit') }}
                </DropdownItem>
                <DropdownItem v-if="!address.isDefault" @select="$emit('makeDefault')">
                    <template #icon><Star aria-hidden="true" /></template>
                    {{ t('contacts.addresses.makeDefault') }}
                </DropdownItem>
                <DropdownSeparator />
                <DropdownItem tone="danger" @select="$emit('delete')">
                    <template #icon><Trash2 aria-hidden="true" /></template>
                    {{ t('common.actions.delete') }}
                </DropdownItem>
            </Dropdown>
        </div>
    </article>
</template>

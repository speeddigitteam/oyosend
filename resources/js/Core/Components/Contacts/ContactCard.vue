<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Mail, MapPin, Phone, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import { mailHref, peopleOfCompanyUrl, telHref } from './links';
import RowActions from './RowActions.vue';
import type { ContactRow } from './types';
import { initialsOf } from './view';

/**
 * One person as a card in the grid view (Odoo's kanban card): who they are, where they work and how to reach them.
 * The name opens the details; the menu carries the same actions as a list row, offered only where the server said
 * this person's permission reaches.
 */
const props = defineProps<{
    contact: ContactRow;
    /** The country already turned into a name in the viewer's language. */
    countryLabel: string | null;
    /** On the company's own page the company goes without saying. */
    hideCompany?: boolean;
}>();

defineEmits<{ view: []; edit: []; duplicates: []; delete: [] }>();

const { t } = useI18n();

const company = computed(() => (props.hideCompany ? null : props.contact.company));
const place = computed(() => [props.contact.city, props.countryLabel].filter((part): part is string => Boolean(part)).join(', '));

const LINK = 'min-w-0 truncate rounded-chip underline-offset-4 transition-colors hover:text-brand-text hover:underline';
</script>

<template>
    <article class="glass relative flex min-w-0 gap-3 rounded-card p-4 transition-shadow hover:shadow-lg" :data-contact-card="contact.id">
        <Avatar :initials="initialsOf(contact.name)" :name="contact.name" size="lg" />

        <div class="min-w-0 flex-1 pr-8">
            <h3 class="min-w-0">
                <button
                    type="button"
                    class="block max-w-full truncate rounded-chip text-left text-[0.9375rem] font-semibold text-ink underline-offset-4 hover:text-brand-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                    :title="contact.name"
                    @click="$emit('view')"
                >
                    {{ contact.name }}
                </button>
            </h3>

            <p v-if="contact.jobTitle || company" class="mt-0.5 flex min-w-0 items-center gap-1 text-[0.8125rem] text-ink-muted">
                <span v-if="contact.jobTitle" class="min-w-0 truncate" :title="contact.jobTitle">{{ contact.jobTitle }}</span>
                <span v-if="contact.jobTitle && company" aria-hidden="true">·</span>
                <Link v-if="company" :href="peopleOfCompanyUrl(company.id)" :title="t('contacts.people.showCompany', { company: company.name })" :class="[LINK, 'text-ink']">
                    {{ company.name }}
                </Link>
            </p>

            <ul class="mt-3 grid gap-1 text-[0.8125rem] text-ink-muted">
                <li v-if="contact.email" class="flex min-w-0 items-center gap-2">
                    <Mail class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('contacts.fields.email') }}:</span>
                    <a :href="mailHref(contact.email)" :title="contact.email" :class="LINK">{{ contact.email }}</a>
                </li>
                <li v-if="contact.phone" class="flex min-w-0 items-center gap-2">
                    <Phone class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('contacts.fields.phone') }}:</span>
                    <a v-if="telHref(contact.phone)" :href="telHref(contact.phone)" :class="[LINK, 'tabular']">{{ contact.phone }}</a>
                    <span v-else class="tabular truncate">{{ contact.phone }}</span>
                </li>
                <li v-if="place" class="flex min-w-0 items-center gap-2">
                    <MapPin class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <span class="sr-only">{{ t('contacts.fields.location') }}:</span>
                    <span class="truncate" :title="place">{{ place }}</span>
                </li>
            </ul>

            <p class="mt-3 flex min-w-0 items-center gap-2 border-t border-line pt-2.5 text-xs text-ink-subtle">
                <UserRound class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="sr-only">{{ t('contacts.fields.owner') }}:</span>
                <span class="truncate">{{ contact.owner?.name ?? t('contacts.list.noOwner') }}</span>
            </p>
        </div>

        <div class="absolute top-2.5 right-2.5">
            <RowActions
                :record-id="contact.id"
                :name="contact.name"
                :editable="contact.editable"
                :deletable="contact.deletable"
                :can-merge="contact.editable && contact.deletable"
                @view="$emit('view')"
                @edit="$emit('edit')"
                @duplicates="$emit('duplicates')"
                @delete="$emit('delete')"
            />
        </div>
    </article>
</template>

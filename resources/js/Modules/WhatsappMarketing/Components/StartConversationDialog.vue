<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { route } from '@/Core/lib/route';
import TemplateSendFields from './TemplateSendFields.vue';
import type { ContactMatch, SendableTemplate } from './types';

/**
 * Start a conversation with a contact by sending an approved template: a business can only open a WhatsApp
 * conversation with a template. Contact search is a partial reload of `contactMatches`.
 */
const props = defineProps<{
    templates: SendableTemplate[];
    contactMatches: ContactMatch[];
    /** The inbox filters in force, kept when the search reloads the page. */
    query: Record<string, string>;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const search = ref('');
const searching = ref(false);
const chosen = ref<ContactMatch | null>(null);
const form = useForm<{ contact_id: string; template_id: string; variables: string[] }>({ contact_id: '', template_id: '', variables: [] });
let timer: ReturnType<typeof setTimeout> | null = null;

function lookup(): void {
    searching.value = true;
    router.get(
        route('whatsapp_marketing.inbox.index'),
        { ...props.query, contact_search: search.value.trim() },
        {
            only: ['contactMatches'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => (searching.value = false),
        },
    );
}

watch(search, () => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    if (search.value.trim().length < 2) {
        return;
    }

    timer = setTimeout(lookup, 300);
});
onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

function choose(match: ContactMatch): void {
    chosen.value = match;
    form.contact_id = match.id;
    form.clearErrors();
}

const visibleMatches = computed(() => (search.value.trim().length >= 2 ? props.contactMatches : []));
const ready = computed(() => {
    const picked = props.templates.find((item) => item.id === form.template_id);

    return (
        chosen.value !== null &&
        picked !== undefined &&
        form.variables.length >= picked.variableCount &&
        form.variables.slice(0, picked.variableCount).every((value) => value.trim() !== '')
    );
});

function submit(): void {
    form.post(route('whatsapp_marketing.inbox.store'), {
        onSuccess: () => {
            open.value = false;
            form.reset();
            chosen.value = null;
            search.value = '';
        },
    });
}

const variableError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return Object.entries(errors).find(([key]) => key.startsWith('variables'))?.[1];
});
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="t('whatsapp.start.title')" :description="t('whatsapp.start.description')" :persistent="form.processing">
        <form id="whatsapp-start" class="grid gap-4" novalidate @submit.prevent="submit">
            <div class="grid gap-2">
                <Input v-model="search" :label="t('whatsapp.start.contact')" :placeholder="t('whatsapp.start.contactPlaceholder')" :error="form.errors.contact_id" maxlength="100">
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
                <p v-if="chosen" class="flex flex-wrap items-center gap-2 text-sm" data-testid="chosen-contact">
                    <Badge tone="brand">{{ t('whatsapp.start.chosen') }}</Badge>
                    <span class="wrap-anywhere">{{ chosen.name }}</span>
                    <span class="tabular text-ink-muted">{{ chosen.phone }}</span>
                </p>
                <p v-if="searching" class="text-xs text-ink-subtle">{{ t('whatsapp.common.searching') }}</p>
                <ul v-else-if="visibleMatches.length > 0" class="grid gap-1" :aria-label="t('whatsapp.start.matches')">
                    <li v-for="match in visibleMatches" :key="match.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm hover:bg-hover focus-visible:outline-2 disabled:opacity-55"
                            :disabled="match.validPhone === false"
                            @click="choose(match)"
                        >
                            <span class="wrap-anywhere text-ink">{{ match.name }}</span>
                            <span class="tabular shrink-0 text-ink-muted">{{ match.phone }}</span>
                        </button>
                        <p v-if="match.validPhone === false" class="px-3 text-xs text-warn">{{ t('whatsapp.start.invalidPhone') }}</p>
                    </li>
                </ul>
                <p v-else-if="search.trim().length >= 2" class="text-xs text-ink-subtle">{{ t('whatsapp.start.noMatches') }}</p>
            </div>

            <p v-if="templates.length === 0" class="text-sm text-ink-muted" data-testid="start-no-templates">{{ t('whatsapp.start.noTemplates') }}</p>
            <TemplateSendFields
                v-else
                v-model:template-id="form.template_id"
                v-model:values="form.variables"
                :templates="templates"
                :error="form.errors.template_id"
                :variable-error="variableError"
            />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('whatsapp.common.cancel') }}</Button>
            <Button type="submit" form="whatsapp-start" variant="primary" :loading="form.processing" :disabled="!ready">
                {{ t('whatsapp.start.submit') }}
            </Button>
        </template>
    </Modal>
</template>

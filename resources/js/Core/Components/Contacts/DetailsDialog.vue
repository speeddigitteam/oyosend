<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Pencil } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import type { DetailItem } from './types';

/**
 * Everything recorded about one person or company, read only. The lists show
 * a few columns; this is where the rest can be read - also by someone who may
 * look at a record but not change it.
 */
defineProps<{
    /** Name of the record. */
    title: string;
    description?: string;
    items: DetailItem[];
    /** The viewer may change this record: offers the way to the form. */
    editable?: boolean;
}>();

defineEmits<{ edit: [] }>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const LINK = 'rounded-chip text-ink underline decoration-line-strong underline-offset-4 transition-colors hover:text-brand-text hover:decoration-current';
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="description" size="lg">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
            <div v-for="item in items" :key="item.key" :class="['min-w-0', item.multiline ? 'sm:col-span-2' : '']">
                <dt class="eyebrow mb-1">{{ item.label }}</dt>
                <dd :class="['text-sm wrap-break-word text-ink', item.multiline ? 'whitespace-pre-line' : '']">
                    <span v-if="!item.value" class="text-ink-subtle">
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">{{ t('contacts.list.notRecorded') }}</span>
                    </span>
                    <Link v-else-if="item.href && item.link === 'page'" :href="item.href" :class="LINK">{{ item.value }}</Link>
                    <a
                        v-else-if="item.href && item.link === 'external'"
                        :href="item.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="[LINK, 'inline-flex max-w-full items-center gap-1.5']"
                    >
                        <span class="min-w-0 truncate">{{ item.value }}</span>
                        <ExternalLink class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                        <span class="sr-only">({{ t('contacts.list.opensNewTab') }})</span>
                    </a>
                    <a v-else-if="item.href" :href="item.href" :class="LINK">{{ item.value }}</a>
                    <template v-else>{{ item.value }}</template>
                </dd>
            </div>
        </dl>

        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.actions.close') }}</Button>
            <Button v-if="editable" @click="$emit('edit')">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('common.actions.edit') }}
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { Paperclip, X } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useSupportFormat } from './useSupportFormat';

/** Chooses up to `max` files for a message. Validation of size and type is the server's. */
const props = defineProps<{ max: number; error?: string }>();

const files = defineModel<File[]>({ default: () => [] });
const { t } = useI18n();
const { bytes } = useSupportFormat();
const inputId = useId();
const input = ref<HTMLInputElement | null>(null);
const tooMany = computed(() => files.value.length > props.max);

function choose(event: Event): void {
    const target = event.target as HTMLInputElement;
    files.value = [...files.value, ...Array.from(target.files ?? [])].slice(0, props.max);
    target.value = '';
}

function remove(index: number): void {
    files.value = files.value.filter((_, position) => position !== index);
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <input :id="inputId" ref="input" type="file" multiple class="sr-only" @change="choose" />
            <Button size="sm" variant="ghost" :disabled="files.length >= max" @click="input?.click()">
                <template #icon><Paperclip class="size-4" aria-hidden="true" /></template>
                {{ t('support.attachments.add') }}
            </Button>
            <span class="text-xs text-ink-subtle">{{ t('support.attachments.hint', { max }) }}</span>
        </div>
        <ul v-if="files.length" class="grid gap-1">
            <li v-for="(file, index) in files" :key="`${file.name}-${index}`" class="flex items-center justify-between gap-2 rounded-control bg-hover px-3 py-1.5 text-sm">
                <span class="min-w-0 wrap-anywhere text-ink">{{ file.name }}</span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="tabular text-xs text-ink-subtle">{{ bytes(file.size) }}</span>
                    <Button icon size="sm" variant="ghost" :aria-label="t('support.attachments.remove', { name: file.name })" @click="remove(index)">
                        <template #icon><X class="size-4" aria-hidden="true" /></template>
                    </Button>
                </span>
            </li>
        </ul>
        <p v-if="error || tooMany" class="text-sm text-danger" role="alert">{{ error ?? t('support.attachments.tooMany', { max }) }}</p>
    </div>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ImageIcon, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { route } from '@/Core/lib/route';

/**
 * One letterhead band (header or footer): what it looks like now, and uploading,
 * replacing or removing it. A chosen file is sent straight away. Size and type are
 * checked here first to spare a pointless upload; the server checks again and wins.
 */
const props = defineProps<{
    /** Which band this is; the route takes it as the slot. */
    band: 'header' | 'footer';
    /** Band label, e.g. "Header band". */
    label: string;
    /** Short-lived signed URL, or null when the band is not set. */
    imageUrl: string | null;
}>();

const { t } = useI18n();

const ACCEPT = 'image/png,image/jpeg,image/webp';
const MAX_BYTES = 2 * 1024 * 1024;

const form = useForm<{ file: File | null }>({ file: null });
const picker = ref<HTMLInputElement | null>(null);
const errorId = useId();

const refused = ref<string | null>(null);
const error = computed(() => refused.value ?? form.errors.file ?? null);

function choose(): void {
    picker.value?.click();
}

function upload(event: Event): void {
    const field = event.target as HTMLInputElement;
    const file = field.files?.[0];

    field.value = '';

    if (!file) {
        return;
    }

    refused.value = null;
    form.clearErrors();

    if (file.type !== '' && !ACCEPT.split(',').includes(file.type)) {
        refused.value = t('invoicing.settings.letterheadWrongType');

        return;
    }

    if (file.size > MAX_BYTES) {
        refused.value = t('invoicing.settings.letterheadTooLarge');

        return;
    }

    form.file = file;

    form.post(route('invoicing.settings.letterhead.upload', { slot: props.band }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset(),
    });
}

function remove(): void {
    router.delete(route('invoicing.settings.letterhead.destroy', { slot: props.band }), {
        preserveScroll: true,
        onSuccess: () => {
            refused.value = null;
            form.clearErrors();
        },
    });
}
</script>

<template>
    <div class="grid gap-3 rounded-card border border-line p-4">
        <p class="text-sm font-medium text-ink">{{ label }}</p>

        <div
            class="flex min-h-16 items-center justify-center overflow-hidden rounded-card"
            :class="imageUrl ? 'bg-white ring-1 ring-line' : 'glass-well text-ink-subtle'"
            :aria-busy="form.processing || undefined"
        >
            <img v-if="imageUrl" :src="imageUrl" :alt="label" class="max-h-28 w-full object-contain" decoding="async" />
            <ImageIcon v-else class="size-6" aria-hidden="true" />
        </div>

        <div class="flex flex-wrap gap-2">
            <Button size="sm" :loading="form.processing" :aria-describedby="errorId" @click="choose">
                <template #icon><Upload class="size-4" aria-hidden="true" /></template>
                {{ imageUrl === null ? t('invoicing.settings.letterheadUpload') : t('invoicing.settings.letterheadReplace') }}
            </Button>
            <Button v-if="imageUrl !== null" size="sm" variant="ghost" :disabled="form.processing" @click="remove">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.common.remove') }}
            </Button>
        </div>

        <p :id="errorId" :class="['text-[0.8125rem]', error ? 'text-danger' : 'text-ink-subtle']" :role="error ? 'alert' : undefined">
            {{ error ?? t('invoicing.settings.letterheadRequirements') }}
        </p>

        <input ref="picker" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="ACCEPT" @change="upload" />
    </div>
</template>

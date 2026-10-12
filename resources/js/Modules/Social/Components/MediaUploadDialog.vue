<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { kbToMb } from './socialFormat';
import SocialFormDialog from './SocialFormDialog.vue';

const MAX_FILES = 10;

/** Uploads up to ten files. The server checks the real content type and size of each. */
const props = defineProps<{
    open: boolean;
    accept: { mimes: string[]; imageMaxKb: number; videoMaxKb: number };
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();
const format = useFormat();

const form = useForm<{ files: File[] }>({ files: [] });
const acceptAttribute = computed(() => props.accept.mimes.join(','));

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

const fileErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => key === 'files' || key.startsWith('files.'))
    .map(([, message]) => message));

function choose(event: Event): void {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, MAX_FILES);
}

function submit(): void {
    form.post(route('social.media.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        size="sm"
        :title="t('social.media.upload.title')"
        :description="t('social.media.upload.description', {
            images: format.number(kbToMb(accept.imageMaxKb)),
            videos: format.number(kbToMb(accept.videoMaxKb)),
            max: format.number(MAX_FILES),
        })"
        :submit-label="t('social.media.upload.submit')"
        :processing="form.processing"
        :disabled="form.files.length === 0"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input type="file" multiple :accept="acceptAttribute" :label="t('social.media.upload.files')" @change="choose" />
        <p v-if="form.files.length > 0" class="text-[0.8125rem] text-ink-muted">
            {{ t('social.media.upload.chosen', { count: format.number(form.files.length) }) }}
        </p>
        <ul v-if="fileErrors.length > 0" class="grid gap-1 text-[0.8125rem] text-danger" role="alert">
            <li v-for="(message, index) in fileErrors" :key="index">{{ message }}</li>
        </ul>
    </SocialFormDialog>
</template>

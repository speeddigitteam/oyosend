<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import SocialFormDialog from './SocialFormDialog.vue';
import type { MediaRow } from './types';

/** Renames a file and describes it for people who cannot see it. */
const props = defineProps<{ open: boolean; media: MediaRow | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', alt_text: '' });

watch(() => [props.open, props.media?.id], () => {
    if (props.open && props.media) {
        form.defaults({ name: props.media.name, alt_text: props.media.altText ?? '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    if (!props.media) {
        return;
    }

    form.transform((data) => ({
        name: data.name.trim() === '' ? null : data.name.trim(),
        alt_text: data.alt_text.trim() === '' ? null : data.alt_text.trim(),
    }));
    form.put(route('social.media.update', { media: props.media.id }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        :title="t('social.media.edit.title')"
        :submit-label="t('social.common.save')"
        :processing="form.processing"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('social.fields.name')" maxlength="190" :error="form.errors.name" required />
        <Textarea
            v-model="form.alt_text"
            :label="t('social.media.edit.altText')"
            :hint="t('social.media.edit.altTextHint')"
            maxlength="500"
            optional
            :error="form.errors.alt_text"
        />
    </SocialFormDialog>
</template>

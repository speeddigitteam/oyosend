<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import SocialFormDialog from './SocialFormDialog.vue';

/** Sends a post that is waiting for approval back to draft; the reason is kept with it. */
const props = defineProps<{ open: boolean; postId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ reason: '' });
const usable = computed(() => form.reason.trim() !== '');

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ reason: data.reason.trim() }));
    form.post(route('social.posts.reject', { post: props.postId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        size="sm"
        :title="t('social.reject.title')"
        :description="t('social.reject.description')"
        :submit-label="t('social.reject.submit')"
        :processing="form.processing"
        :disabled="!usable"
        danger
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Textarea v-model="form.reason" :label="t('social.reject.reason')" maxlength="500" :rows="4" :error="form.errors.reason" required />
    </SocialFormDialog>
</template>

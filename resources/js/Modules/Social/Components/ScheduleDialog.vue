<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import { isLocalDateTime } from './socialFormat';
import SocialFormDialog from './SocialFormDialog.vue';

/**
 * Queues a post (now or at a workspace-local time) or moves a queued one.
 * Times are typed in the workspace timezone; the server stores them in UTC.
 */
const props = defineProps<{
    open: boolean;
    postId: string;
    mode: 'schedule' | 'reschedule';
    initial: string | null;
    timezone: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ publish_now: false, scheduled_for: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ publish_now: false, scheduled_for: props.initial ?? '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

const canPublishNow = computed(() => props.mode === 'schedule');
const needsTime = computed(() => !(canPublishNow.value && form.publish_now));
const targetsError = computed(() => (form.errors as Record<string, string | undefined>).targets);
const usable = computed(() => !needsTime.value || isLocalDateTime(form.scheduled_for));

function submit(): void {
    const name = props.mode === 'schedule' ? 'social.posts.schedule' : 'social.posts.reschedule';

    form.transform((data) => (needsTime.value
        ? { scheduled_for: data.scheduled_for }
        : { publish_now: true }));
    form.post(route(name, { post: props.postId }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        size="sm"
        :title="t(`social.schedule.${mode}.title`)"
        :description="t(`social.schedule.${mode}.description`, { timezone })"
        :submit-label="t(`social.schedule.${mode}.submit`)"
        :processing="form.processing"
        :disabled="!usable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Checkbox
            v-if="canPublishNow"
            v-model="form.publish_now"
            :label="t('social.schedule.publishNow')"
            :description="t('social.schedule.publishNowHint')"
        />
        <Input
            v-if="needsTime"
            v-model="form.scheduled_for"
            type="datetime-local"
            :label="t('social.schedule.when', { timezone })"
            :error="form.errors.scheduled_for"
            required
        />
        <p v-if="targetsError" class="text-[0.8125rem] text-danger" role="alert">{{ targetsError }}</p>
    </SocialFormDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { isLocalDateTime } from './socialFormat';
import SocialFormDialog from './SocialFormDialog.vue';

/**
 * Picks the time for the composer's "Schedule": the composer saves the post first and then queues it for this
 * time through the same schedule action as the post page. Times are typed in the workspace timezone.
 */
const props = defineProps<{
    open: boolean;
    /** The planned time already on the draft, `Y-m-d\TH:i`, or empty. */
    initial: string;
    timezone: string;
    processing?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [value: string] }>();
const { t } = useI18n();

const when = ref('');

watch(() => props.open, (open) => {
    if (open) {
        when.value = isLocalDateTime(props.initial) ? props.initial : '';
    }
}, { immediate: true });

const usable = computed(() => isLocalDateTime(when.value));
</script>

<template>
    <SocialFormDialog
        :open="open"
        size="sm"
        :title="t('social.schedule.schedule.title')"
        :description="t('social.schedule.schedule.description', { timezone })"
        :submit-label="t('social.schedule.schedule.submit')"
        :processing="processing"
        :disabled="!usable"
        @update:open="emit('update:open', $event)"
        @submit="emit('confirm', when)"
    >
        <Input v-model="when" type="datetime-local" :label="t('social.schedule.when', { timezone })" required />
        <p class="text-[0.8125rem] text-ink-muted">{{ t('social.composer.scheduleSaves') }}</p>
    </SocialFormDialog>
</template>

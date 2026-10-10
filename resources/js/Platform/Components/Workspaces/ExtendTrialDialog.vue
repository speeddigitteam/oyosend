<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import { route } from '@/Core/lib/route';
import { dateInZone, tomorrowInZone } from './dates';

/**
 * Moves the end of a workspace's trial. The server takes the chosen day to
 * its end on the workspace's own clock, and refuses a workspace that is on a
 * free plan or inside a period it has paid for.
 */
const props = defineProps<{
    workspace: { id: string; name: string; timezone: string };
    /** When the trial ends now, if the workspace is on one. */
    trialEndsAt: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const form = useForm({ trial_ends_at: '' });
const dateInput = ref<InstanceType<typeof Input> | null>(null);

/** The first day the server accepts: tomorrow, on the workspace's own clock. */
const firstDay = computed(() => tomorrowInZone(props.workspace.timezone));

// "This workspace has no subscription" arrives under a key of its own.
const error = computed(() => form.errors.trial_ends_at ?? (form.errors as Record<string, string | undefined>).subscription);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    // Start from the end the trial has now, when that is still ahead.
    const current = props.trialEndsAt ? dateInZone(new Date(props.trialEndsAt), props.workspace.timezone) : '';

    form.trial_ends_at = current >= firstDay.value ? current : '';

    // The dialog focuses its close button by default; start in the field instead.
    await nextTick();
    requestAnimationFrame(() => dateInput.value?.focus());
});

function submit(): void {
    form.post(route('platform.tenants.subscription.trial', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="t('platformWorkspaces.trial.title', { name: workspace.name })"
        :description="t('platformWorkspaces.trial.description', { timezone: workspace.timezone })"
        :persistent="form.processing"
    >
        <form :id="formId" novalidate @submit.prevent="submit">
            <Input
                ref="dateInput"
                v-model="form.trial_ends_at"
                type="date"
                name="trial_ends_at"
                :label="t('platformWorkspaces.trial.until')"
                :error="error"
                :min="firstDay"
                required
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="form.trial_ends_at === ''">
                {{ t('platformWorkspaces.trial.submit') }}
            </Button>
        </template>
    </Modal>
</template>

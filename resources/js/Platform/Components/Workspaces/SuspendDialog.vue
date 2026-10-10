<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';

/**
 * Blocks every request to a workspace. The reason is required: it is what
 * the owner of the workspace reads in their own audit log afterwards.
 */
const props = defineProps<{
    workspace: { id: string; name: string };
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const form = useForm({ reason: '' });
const reasonInput = ref<InstanceType<typeof Textarea> | null>(null);

// "Only an active workspace can be suspended" arrives under a key of its own.
const error = computed(() => form.errors.reason ?? (form.errors as Record<string, string | undefined>).workspace);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    // The dialog focuses its close button by default; start in the field instead.
    await nextTick();
    requestAnimationFrame(() => reasonInput.value?.focus());
});

function suspend(): void {
    form.post(route('platform.tenants.suspend', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        :title="t('platformWorkspaces.suspend.title', { name: workspace.name })"
        :description="t('platformWorkspaces.suspend.body')"
        :confirm-label="t('platformWorkspaces.suspend.confirm')"
        :loading="form.processing"
        @confirm="suspend"
    >
        <Textarea
            ref="reasonInput"
            v-model="form.reason"
            name="reason"
            :label="t('platformWorkspaces.suspend.reason')"
            :hint="t('platformWorkspaces.suspend.reasonHint')"
            :error="error"
            :rows="3"
            maxlength="500"
            required
        />
    </ConfirmDialog>
</template>

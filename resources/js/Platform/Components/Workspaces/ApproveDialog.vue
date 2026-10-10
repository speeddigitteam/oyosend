<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import { route } from '@/Core/lib/route';

/**
 * Lets a sign-up that was waiting for approval in. The server refuses a
 * workspace that is no longer pending and says so; that message is shown here.
 */
const props = defineProps<{
    workspace: { id: string; name: string };
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const processing = ref(false);
const error = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        error.value = null;
    }
});

function approve(): void {
    processing.value = true;
    error.value = null;

    router.post(
        route('platform.tenants.approve', { workspace: props.workspace.id }),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                error.value = errors.workspace ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        tone="default"
        :title="t('platformWorkspaces.approve.title', { name: workspace.name })"
        :description="t('platformWorkspaces.approve.body')"
        :confirm-label="t('platformWorkspaces.approve.confirm')"
        :loading="processing"
        @confirm="approve"
    >
        <p v-if="error" class="text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
    </ConfirmDialog>
</template>

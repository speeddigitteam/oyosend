<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';

/**
 * Brings a suspended workspace back. A reason is welcome but not asked for:
 * giving access back is the harmless direction.
 */
const props = defineProps<{
    workspace: { id: string; name: string };
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const form = useForm({ reason: '' });

// "This workspace is not suspended" arrives under a key of its own.
const error = computed(() => form.errors.reason ?? (form.errors as Record<string, string | undefined>).workspace);

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function reactivate(): void {
    form.transform((data) => ({ reason: data.reason.trim() === '' ? null : data.reason })).post(
        route('platform.tenants.reactivate', { workspace: props.workspace.id }),
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        tone="default"
        :title="t('platformWorkspaces.reactivate.title', { name: workspace.name })"
        :description="t('platformWorkspaces.reactivate.body')"
        :confirm-label="t('platformWorkspaces.reactivate.confirm')"
        :loading="form.processing"
        @confirm="reactivate"
    >
        <Textarea
            v-model="form.reason"
            name="reason"
            :label="t('platformWorkspaces.reactivate.reason')"
            :hint="t('platformWorkspaces.reactivate.reasonHint')"
            :error="error"
            :rows="2"
            maxlength="500"
            optional
        />
    </ConfirmDialog>
</template>

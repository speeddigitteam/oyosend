<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ImageIcon, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { route } from '@/Core/lib/route';

/**
 * The workspace's logo: what it looks like now, and uploading, replacing or
 * removing it. A chosen file is sent straight away - there is nothing else to
 * save with it - so this card stands outside the profile form.
 *
 * Size and type are checked here first to spare a pointless upload; the
 * server checks again and has the final word.
 */
const props = defineProps<{
    /** Short-lived signed URL, or null when the workspace has no logo. */
    logoUrl: string | null;
    /** Name of the workspace. */
    workspace: string;
}>();

const { t } = useI18n();

const ACCEPT = 'image/png,image/jpeg,image/webp';
const MAX_BYTES = 1024 * 1024;

const form = useForm<{ logo: File | null }>({ logo: null });
const picker = ref<HTMLInputElement | null>(null);
const errorId = useId();

// Refused before anything was sent, as opposed to `form.errors.logo`, which the server sent back.
const refused = ref<string | null>(null);
const error = computed(() => refused.value ?? form.errors.logo ?? null);

// An image that will not load (the signed URL ran out on a page left open) gives way to the placeholder.
const broken = ref(false);
const shown = computed(() => props.logoUrl !== null && !broken.value);

watch(
    () => props.logoUrl,
    () => {
        broken.value = false;
    },
);

function choose(): void {
    picker.value?.click();
}

function upload(event: Event): void {
    const field = event.target as HTMLInputElement;
    const file = field.files?.[0];

    // Emptied at once, so the same file can be chosen again after a refusal.
    field.value = '';

    if (!file) {
        return;
    }

    refused.value = null;
    form.clearErrors();

    // Some systems report no type at all; those are left to the server.
    if (file.type !== '' && !ACCEPT.split(',').includes(file.type)) {
        refused.value = t('workspace.settings.logo.wrongType');

        return;
    }

    if (file.size > MAX_BYTES) {
        refused.value = t('workspace.settings.logo.tooLarge');

        return;
    }

    form.logo = file;

    form.post(route('core.settings.workspace.logo.store'), {
        preserveScroll: true,
        forceFormData: true,
        // In both callbacks rather than in onFinish: after a success the form adopts its current values as defaults.
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            form.reset();
        },
    });
}

/*
 * Removing
 */
const confirmOpen = ref(false);
const removing = ref(false);
const removeError = ref<string | null>(null);

// The Remove button is gone once the logo is; focus then belongs on the upload button.
useFocusReturn(confirmOpen, () => (props.logoUrl === null ? '[data-logo-upload]' : '[data-logo-remove]'));

function askRemove(): void {
    removeError.value = null;
    confirmOpen.value = true;
}

function remove(): void {
    removing.value = true;
    removeError.value = null;

    router.delete(route('core.settings.workspace.logo.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            refused.value = null;
            form.clearErrors();
            confirmOpen.value = false;
        },
        onError: (errors) => {
            removeError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}
</script>

<template>
    <Card :title="t('workspace.settings.logo.title')" :description="t('workspace.settings.logo.description', { workspace })">
        <div class="flex items-center gap-4">
            <!-- The square tile the sidebar uses, larger. White behind the image in both themes: logos are drawn for a light page. -->
            <div
                :class="[
                    'flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-card',
                    shown ? 'bg-white ring-1 ring-line' : 'glass-well text-ink-subtle',
                ]"
                :aria-busy="form.processing || undefined"
            >
                <img
                    v-if="shown && logoUrl"
                    :src="logoUrl"
                    :alt="t('workspace.settings.logo.alt', { workspace })"
                    class="size-full object-contain"
                    decoding="async"
                    @error="broken = true"
                />
                <ImageIcon v-else class="size-6" aria-hidden="true" />
            </div>

            <div class="min-w-0 flex-1">
                <p v-if="logoUrl === null" class="mb-3 text-[0.8125rem] text-ink-muted">{{ t('workspace.settings.logo.empty') }}</p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        :loading="form.processing"
                        :aria-describedby="errorId"
                        data-logo-upload
                        @click="choose"
                    >
                        <template #icon><Upload class="size-4" aria-hidden="true" /></template>
                        {{ logoUrl === null ? t('workspace.settings.logo.upload') : t('workspace.settings.logo.replace') }}
                    </Button>
                    <Button v-if="logoUrl !== null" size="sm" variant="ghost" :disabled="form.processing" data-logo-remove @click="askRemove">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('common.actions.remove') }}
                    </Button>
                </div>
            </div>
        </div>

        <p :id="errorId" :class="['mt-4 text-[0.8125rem]', error ? 'text-danger' : 'text-ink-subtle']" :role="error ? 'alert' : undefined">
            {{ error ?? t('workspace.settings.logo.requirements') }}
        </p>

        <!-- Opened by the button above, which is what keyboard and screen-reader users meet. -->
        <input ref="picker" type="file" name="logo" class="sr-only" tabindex="-1" aria-hidden="true" :accept="ACCEPT" @change="upload" />
    </Card>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="t('workspace.settings.logo.removeDialog.title')"
        :description="t('workspace.settings.logo.removeDialog.body', { workspace })"
        :confirm-label="t('workspace.settings.logo.removeDialog.confirm')"
        :loading="removing"
        @confirm="remove"
    >
        <p v-if="removeError" class="text-[0.8125rem] text-danger" role="alert">{{ removeError }}</p>
    </ConfirmDialog>
</template>

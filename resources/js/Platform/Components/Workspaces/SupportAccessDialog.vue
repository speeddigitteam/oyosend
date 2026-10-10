<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Eye, FileClock, Timer, UserRoundX } from 'lucide-vue-next';
import { nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';

/**
 * Opens a support session: the operator states why and is taken into the
 * workspace as its owner. What that means is said before the reason is asked
 * for, not after.
 *
 * The server answers with a redirect to the workspace's own address, which
 * Inertia follows as a full page load: this page is left behind.
 */
const props = defineProps<{
    workspace: { id: string; name: string };
    /** Who the operator will be signed in as. */
    owner: { name: string; email: string };
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const form = useForm({ reason: '' });
const reasonInput = ref<InstanceType<typeof Textarea> | null>(null);

const RULES = [
    { key: 'timed', icon: Timer },
    { key: 'visible', icon: Eye },
    { key: 'recorded', icon: FileClock },
    { key: 'personal', icon: UserRoundX },
] as const;

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

function submit(): void {
    form.post(route('platform.tenants.impersonate', { workspace: props.workspace.id }), { preserveScroll: true });
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="t('platformWorkspaces.impersonate.title', { name: workspace.name })"
        :description="t('platformWorkspaces.impersonate.description', { owner: owner.email })"
        :persistent="form.processing"
    >
        <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <div class="glass-well rounded-control px-4 py-3.5">
                <p class="eyebrow">{{ t('platformWorkspaces.impersonate.rulesTitle') }}</p>
                <ul class="mt-2.5 flex flex-col gap-2.5">
                    <li v-for="rule in RULES" :key="rule.key" class="flex items-start gap-2.5 text-[0.8125rem] text-ink-muted">
                        <component :is="rule.icon" class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                        <span class="min-w-0">{{ t(`platformWorkspaces.impersonate.rules.${rule.key}`) }}</span>
                    </li>
                </ul>
            </div>

            <!-- The server reports a workspace that cannot be opened (not active, no owner) under the same key. -->
            <Textarea
                ref="reasonInput"
                v-model="form.reason"
                name="reason"
                :label="t('platformWorkspaces.impersonate.reason')"
                :hint="t('platformWorkspaces.impersonate.reasonHint')"
                :error="form.errors.reason"
                :rows="3"
                maxlength="500"
                required
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing">
                {{ t('platformWorkspaces.impersonate.submit') }}
            </Button>
        </template>
    </Modal>
</template>

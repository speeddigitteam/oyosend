<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { BranchRow } from './types';

/**
 * Adds a branch, or changes one: pass the branch to edit, or `null` to add.
 */
const props = defineProps<{
    branch: BranchRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const nameInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({ name: '', code: '', phone: '', address: '' });

const original = computed(() => ({
    name: props.branch?.name ?? '',
    code: props.branch?.code ?? '',
    phone: props.branch?.phone ?? '',
    address: props.branch?.address ?? '',
}));

// Nothing to save while an existing branch reads exactly as it did.
const unchanged = computed(
    () =>
        props.branch !== null &&
        form.name === original.value.name &&
        form.code === original.value.code &&
        form.phone === original.value.phone &&
        form.address === original.value.address,
);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    form.name = original.value.name;
    form.code = original.value.code;
    form.phone = original.value.phone;
    form.address = original.value.address;

    // The dialog focuses its close button by default; start in the form instead.
    await nextTick();
    requestAnimationFrame(() => nameInput.value?.focus());
});

function submit(): void {
    if (unchanged.value) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (errors: Record<string, string>) => {
            if (errors.name) {
                void nextTick(() => nameInput.value?.focus());
            }
        },
    };

    if (props.branch) {
        form.put(route('core.branches.update', { branch: props.branch.id }), options);
    } else {
        form.post(route('core.branches.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="branch ? t('organization.branches.form.editTitle', { name: branch.name }) : t('organization.branches.form.createTitle')"
        :description="t('organization.branches.form.intro')"
        :persistent="form.processing"
    >
        <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <Input
                    ref="nameInput"
                    v-model="form.name"
                    name="name"
                    :label="t('organization.form.name')"
                    :error="form.errors.name"
                    maxlength="120"
                    autocomplete="off"
                    required
                />
                <Input
                    v-model="form.code"
                    name="code"
                    :label="t('organization.form.code')"
                    :hint="t('organization.form.codeHint')"
                    :error="form.errors.code"
                    maxlength="30"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    mono
                    optional
                />
            </div>

            <Input
                v-model="form.phone"
                type="tel"
                name="phone"
                :label="t('organization.branches.form.phone')"
                :error="form.errors.phone"
                maxlength="40"
                inputmode="tel"
                autocomplete="off"
                optional
            />

            <Textarea
                v-model="form.address"
                name="address"
                :label="t('organization.branches.form.address')"
                :error="form.errors.address"
                :rows="3"
                maxlength="500"
                autocomplete="off"
                optional
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="unchanged">
                {{ branch ? t('common.actions.save') : t('organization.branches.add') }}
            </Button>
        </template>
    </Modal>
</template>

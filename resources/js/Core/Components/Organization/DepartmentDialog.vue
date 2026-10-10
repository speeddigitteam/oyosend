<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { DepartmentRow } from './types';

/**
 * Adds a department, or changes one: pass the department to edit, or `null` to add.
 */
const props = defineProps<{
    department: DepartmentRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const nameInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({ name: '', code: '', description: '' });

const original = computed(() => ({
    name: props.department?.name ?? '',
    code: props.department?.code ?? '',
    description: props.department?.description ?? '',
}));

// Nothing to save while an existing department reads exactly as it did.
const unchanged = computed(
    () =>
        props.department !== null &&
        form.name === original.value.name &&
        form.code === original.value.code &&
        form.description === original.value.description,
);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    form.name = original.value.name;
    form.code = original.value.code;
    form.description = original.value.description;

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

    if (props.department) {
        form.put(route('core.departments.update', { department: props.department.id }), options);
    } else {
        form.post(route('core.departments.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="
            department
                ? t('organization.departments.form.editTitle', { name: department.name })
                : t('organization.departments.form.createTitle')
        "
        :description="t('organization.departments.form.intro')"
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

            <Textarea
                v-model="form.description"
                name="description"
                :label="t('organization.departments.form.description')"
                :error="form.errors.description"
                :rows="2"
                maxlength="255"
                optional
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="unchanged">
                {{ department ? t('common.actions.save') : t('organization.departments.add') }}
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Member, UnitOption } from './types';

/**
 * Puts a member in a branch and a department; either may be none.
 *
 * Only the pickers the workspace has something for are shown, and the page
 * does not open this dialog at all when it has neither.
 */
const props = defineProps<{
    member: Member | null;
    branches: UnitOption[];
    departments: UnitOption[];
    /** The branches and departments page, for a viewer who may add to it. */
    organizationUrl?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const branchId = useId();
const departmentId = useId();

// An empty string stands for "none": a native select has no null.
const form = useForm({ branch_id: '', department_id: '' });

const current = computed(() => ({
    branch_id: props.member?.branch?.id ?? '',
    department_id: props.member?.department?.id ?? '',
}));

const branchOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('team.placement.noBranch') },
    ...props.branches.map((branch) => ({ value: branch.id, label: branch.name })),
]);

const departmentOptions = computed<SelectOption[]>(() => [
    { value: '', label: t('team.placement.noDepartment') },
    ...props.departments.map((department) => ({ value: department.id, label: department.name })),
]);

const unchanged = computed(
    () => form.branch_id === current.value.branch_id && form.department_id === current.value.department_id,
);

// The server reports "you cannot change this member" under a key of its own.
const memberError = computed(() => (form.errors as Record<string, string | undefined>).member);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    form.branch_id = current.value.branch_id;
    form.department_id = current.value.department_id;

    // The dialog focuses its close button by default; start on the first picker instead.
    await nextTick();
    requestAnimationFrame(() => document.getElementById(props.branches.length > 0 ? branchId : departmentId)?.focus());
});

function submit(): void {
    if (!props.member || unchanged.value) {
        return;
    }

    form.transform((data) => ({
        branch_id: data.branch_id === '' ? null : data.branch_id,
        department_id: data.department_id === '' ? null : data.department_id,
    })).patch(route('core.members.update', { membership: props.member.id }), {
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
        :title="t('team.placement.title', { name: member?.name })"
        :description="t('team.placement.description')"
        size="sm"
        :persistent="form.processing"
    >
        <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Select
                v-if="branches.length > 0"
                :id="branchId"
                v-model="form.branch_id"
                name="branch_id"
                :label="t('team.placement.branch')"
                :options="branchOptions"
                :error="form.errors.branch_id"
            />

            <Select
                v-if="departments.length > 0"
                :id="departmentId"
                v-model="form.department_id"
                name="department_id"
                :label="t('team.placement.department')"
                :options="departmentOptions"
                :error="form.errors.department_id"
            />

            <p v-if="memberError" class="text-[0.8125rem] text-danger" role="alert">{{ memberError }}</p>

            <p v-if="organizationUrl" class="text-[0.8125rem] text-ink-subtle">
                {{ t('team.placement.missing') }}
                <Link :href="organizationUrl" class="rounded-chip font-medium text-brand-text underline-offset-4 hover:underline">
                    {{ t('team.placement.manage') }}
                </Link>
            </p>
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="unchanged">
                {{ t('common.actions.save') }}
            </Button>
        </template>
    </Modal>
</template>

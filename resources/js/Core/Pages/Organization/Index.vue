<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Info, Lock, MapPin, Phone } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import BranchDialog from '@/Core/Components/Organization/BranchDialog.vue';
import DepartmentDialog from '@/Core/Components/Organization/DepartmentDialog.vue';
import type { BranchRow, DepartmentRow, UnitDetail, UnitKind } from '@/Core/Components/Organization/types';
import UnitCard from '@/Core/Components/Organization/UnitCard.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

defineProps<{
    /** Every branch of the workspace, by name, with the number of people placed in it. */
    branches: BranchRow[];
    /** Every department of the workspace, by name, with the number of people placed in it. */
    departments: DepartmentRow[];
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const { can } = useAccess();

const canManage = computed(() => can('core.organization.manage'));
/** Putting people in a branch or a department is done on the Members page, by those who manage members. */
const canPlace = computed(() => can('core.members.view') && can('core.members.manage'));

function branchDetails(branch: BranchRow): UnitDetail[] {
    const details: UnitDetail[] = [];

    if (branch.phone) {
        details.push({ text: branch.phone, icon: Phone, label: t('organization.branches.form.phone') });
    }

    if (branch.address) {
        details.push({ text: branch.address, icon: MapPin, label: t('organization.branches.form.address') });
    }

    return details;
}

function departmentDetails(department: DepartmentRow): UnitDetail[] {
    return department.description ? [{ text: department.description }] : [];
}

/*
 * Adding and editing: one dialog per kind, opened with the row to change or
 * with nothing to add a new one.
 */
const branchOpen = ref(false);
const branchTarget = ref<BranchRow | null>(null);

function openBranch(branch: BranchRow | null): void {
    branchTarget.value = branch;
    branchOpen.value = true;
}

const departmentOpen = ref(false);
const departmentTarget = ref<DepartmentRow | null>(null);

function openDepartment(department: DepartmentRow | null): void {
    departmentTarget.value = department;
    departmentOpen.value = true;
}

/*
 * Deleting. Both kinds share one confirmation; what it says depends on how
 * many people sit in the branch or department being deleted.
 */
const pending = ref<{ kind: UnitKind; unit: BranchRow | DepartmentRow } | null>(null);
const confirmOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

// The row, and the button that opened the dialog with it, are gone afterwards.
useFocusReturn(confirmOpen);

function askDelete(kind: UnitKind, unit: BranchRow | DepartmentRow): void {
    pending.value = { kind, unit };
    deleteError.value = null;
    confirmOpen.value = true;
}

const confirmCopy = computed(() => {
    const target = pending.value;

    if (!target) {
        return { title: '', body: '', access: null, confirm: '' };
    }

    const { kind, unit } = target;
    const amount = unit.members === 0 ? 'none' : unit.members === 1 ? 'one' : 'other';

    return {
        title: t(`organization.${kind}.delete.title`, { name: unit.name }),
        body: t(`organization.${kind}.delete.body.${amount}`, { count: format.number(unit.members) }),
        // Nobody sits there, so nobody's access changes.
        access: unit.members > 0 ? t(`organization.${kind}.delete.access`) : null,
        confirm: t(`organization.${kind}.delete.confirm`),
    };
});

function destroy(): void {
    const target = pending.value;

    if (!target) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;

    const url =
        target.kind === 'branches'
            ? route('core.branches.destroy', { branch: target.unit.id })
            : route('core.departments.destroy', { department: target.unit.id });

    router.delete(url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('organization.title')"
        :description="t('organization.description', { workspace: page.props.tenant?.name })"
    />

    <div class="grid items-start gap-3 xl:grid-cols-2">
        <UnitCard
            kind="branches"
            :units="branches"
            :can-manage="canManage"
            :details="branchDetails"
            @add="openBranch(null)"
            @edit="openBranch"
            @remove="askDelete('branches', $event)"
        />

        <UnitCard
            kind="departments"
            :units="departments"
            :can-manage="canManage"
            :details="departmentDetails"
            @add="openDepartment(null)"
            @edit="openDepartment"
            @remove="askDelete('departments', $event)"
        />
    </div>

    <p v-if="!canManage" class="mt-4 flex items-start gap-2 px-1 text-[0.8125rem] text-ink-subtle">
        <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        {{ t('organization.viewOnly') }}
    </p>
    <!-- Placing people happens on another page; say so to those who can do it, once there is somewhere to place them. -->
    <p v-else-if="canPlace && (branches.length > 0 || departments.length > 0)" class="mt-4 flex items-start gap-2 px-1 text-[0.8125rem] text-ink-subtle">
        <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span>
            {{ t('organization.placeHint') }}
            <Link :href="route('core.members.index')" class="rounded-chip font-medium text-brand-text underline-offset-4 hover:underline">
                {{ t('organization.goToMembers') }}
            </Link>
        </span>
    </p>

    <template v-if="canManage">
        <BranchDialog v-model:open="branchOpen" :branch="branchTarget" />
        <DepartmentDialog v-model:open="departmentOpen" :department="departmentTarget" />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.body"
            :confirm-label="confirmCopy.confirm"
            :loading="deleting"
            @confirm="destroy"
        >
            <div v-if="confirmCopy.access || deleteError" class="flex flex-col gap-2">
                <p v-if="confirmCopy.access" class="text-sm text-ink-muted">{{ confirmCopy.access }}</p>
                <p v-if="deleteError" class="text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
            </div>
        </ConfirmDialog>
    </template>
</template>

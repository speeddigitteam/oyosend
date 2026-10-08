<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Info, Lock, Plus, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import PermissionMeter from '@/Core/Components/Team/PermissionMeter.vue';
import type { RoleSummary } from '@/Core/Components/Team/types';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** System roles first, then the workspace's own, each in name order. */
    roles: RoleSummary[];
    /** How many permissions a role in this workspace can carry. */
    totalPermissions: number;
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const { can } = useAccess();

const canManage = computed(() => can('core.roles.manage'));

/** The Owner role stores no grants: its access comes with owning the workspace. */
function granted(role: RoleSummary): number {
    return role.isOwner ? props.totalPermissions : Math.min(role.permissions, props.totalPermissions);
}

function grantedText(role: RoleSummary): string {
    const total = format.number(props.totalPermissions);

    return role.isOwner
        ? t('roles.index.grantedAll', { total })
        : t('roles.index.granted', { count: format.number(granted(role)), total });
}

/*
 * Deleting. A role that people still hold cannot be deleted, so that case
 * explains what to do instead of offering a confirmation that would fail.
 */
const target = ref<RoleSummary | null>(null);
const confirmOpen = ref(false);
const inUseOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

useFocusReturn(confirmOpen);
useFocusReturn(inUseOpen);

function askDelete(role: RoleSummary): void {
    target.value = role;
    deleteError.value = null;

    if (role.members > 0) {
        inUseOpen.value = true;
    } else {
        confirmOpen.value = true;
    }
}

function destroy(): void {
    if (!target.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;

    router.delete(route('core.roles.destroy', { role: target.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
        // Open invitations for the role also block deleting it; only the server knows about those.
        onError: (errors) => {
            deleteError.value = errors.role ?? Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

const inUseText = computed(() => {
    const members = target.value?.members ?? 0;

    return t(members === 1 ? 'roles.delete.inUse.one' : 'roles.delete.inUse.other', { count: format.number(members) });
});

// Rows share the list's columns (subgrid), so figures line up without fixed widths.
const GRID_MANAGE = 'grid grid-cols-1 gap-x-6 sm:grid-cols-[minmax(0,1fr)_auto_minmax(9rem,13rem)_auto]';
const GRID_VIEW = 'grid grid-cols-1 gap-x-6 sm:grid-cols-[minmax(0,1fr)_auto_minmax(9rem,13rem)]';
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('roles.index.title')"
        :description="t('roles.index.description', { workspace: page.props.tenant?.name })"
    >
        <template v-if="canManage" #actions>
            <Button variant="primary" :href="route('core.roles.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('roles.index.create') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <EmptyState v-if="roles.length === 0" :title="t('roles.index.empty')">
            <template #icon><ShieldCheck /></template>
        </EmptyState>

        <ul v-else :class="canManage ? GRID_MANAGE : GRID_VIEW">
            <!-- Column headings for wide screens; each row labels its own figures for everyone else. -->
            <li
                class="eyebrow col-span-full hidden grid-cols-subgrid items-center border-b border-line bg-hover px-5 py-2.5 sm:grid"
                aria-hidden="true"
            >
                <span>{{ t('roles.index.columns.role') }}</span>
                <span class="text-right">{{ t('roles.index.columns.people') }}</span>
                <span>{{ t('roles.index.columns.permissions') }}</span>
                <span v-if="canManage" class="sr-only">{{ t('roles.index.columns.actions') }}</span>
            </li>

            <li
                v-for="role in roles"
                :key="role.id"
                class="col-span-full grid grid-cols-subgrid items-center gap-y-3 border-b border-line px-5 py-4 transition-colors last:border-b-0 hover:bg-hover"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <h2 class="min-w-0 text-[0.9375rem] font-semibold tracking-tight wrap-break-word text-ink">
                            <Link v-if="canManage" :href="route('core.roles.edit', { role: role.id })" class="rounded-chip hover:underline">
                                {{ role.name }}
                            </Link>
                            <template v-else>{{ role.name }}</template>
                        </h2>
                        <Badge v-if="role.isSystem">{{ t('roles.index.system') }}</Badge>
                    </div>
                    <p v-if="role.description" class="mt-1 max-w-xl text-[0.8125rem] wrap-break-word text-ink-muted">{{ role.description }}</p>
                </div>

                <p class="flex items-baseline justify-between gap-4 sm:block sm:text-right">
                    <span class="eyebrow sm:sr-only">{{ t('roles.index.columns.people') }}</span>
                    <span :class="['tabular text-sm', role.members > 0 ? 'text-ink' : 'text-ink-subtle']">{{ format.number(role.members) }}</span>
                </p>

                <div>
                    <p class="mb-1.5 flex items-baseline justify-between gap-4 sm:block">
                        <span class="eyebrow sm:sr-only">{{ t('roles.index.columns.permissions') }}</span>
                        <span class="tabular text-[0.8125rem] whitespace-nowrap text-ink-muted">{{ grantedText(role) }}</span>
                    </p>
                    <PermissionMeter :value="granted(role)" :max="totalPermissions" />
                </div>

                <div v-if="canManage" class="flex items-center gap-1 sm:justify-end">
                    <Button
                        size="sm"
                        :href="route('core.roles.edit', { role: role.id })"
                        :aria-label="t(role.isOwner ? 'roles.index.viewRole' : 'roles.index.editRole', { name: role.name })"
                    >
                        {{ role.isOwner ? t('roles.index.view') : t('roles.index.edit') }}
                    </Button>
                    <Button v-if="!role.isSystem" icon size="sm" variant="ghost" @click="askDelete(role)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('roles.index.delete', { name: role.name }) }}
                    </Button>
                    <!-- Keeps the Edit buttons in one column next to rows that can be deleted. -->
                    <span v-else class="hidden size-8 sm:block" aria-hidden="true" />
                </div>
            </li>
        </ul>

        <template v-if="roles.length > 0" #footer>
            <p class="flex items-start gap-2 text-[0.8125rem] text-ink-subtle">
                <Info v-if="canManage" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <Lock v-else class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {{ canManage ? t('roles.index.systemNote') : t('roles.index.viewOnly') }}
            </p>
        </template>
    </Card>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="t('roles.delete.title', { name: target?.name })"
        :description="t('roles.delete.body')"
        :confirm-label="t('roles.delete.confirm')"
        :loading="deleting"
        @confirm="destroy"
    >
        <p v-if="deleteError" class="text-[0.8125rem] text-danger" role="alert">{{ deleteError }}</p>
    </ConfirmDialog>

    <Modal v-model:open="inUseOpen" :title="t('roles.delete.inUseTitle', { name: target?.name })" size="sm">
        <p class="text-sm text-ink-muted">{{ inUseText }}</p>

        <template #footer>
            <Button variant="ghost" @click="inUseOpen = false">{{ t('common.actions.close') }}</Button>
            <Button v-if="can('core.members.view')" :href="route('core.members.index')">{{ t('roles.delete.goToMembers') }}</Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    CirclePause,
    CirclePlay,
    Ellipsis,
    Info,
    Network,
    Search,
    SearchX,
    ShieldCheck,
    ShieldOff,
    UserCog,
    UserMinus,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import ChangeRoleDialog from '@/Core/Components/Team/ChangeRoleDialog.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import InvitationList from '@/Core/Components/Team/InvitationList.vue';
import InviteMemberDialog from '@/Core/Components/Team/InviteMemberDialog.vue';
import PlacementDialog from '@/Core/Components/Team/PlacementDialog.vue';
import type { Member, OpenInvitation, RoleOption, UnitOption } from '@/Core/Components/Team/types';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    members: Paginated<Member>;
    /** Empty unless the person may invite. */
    invitations: OpenInvitation[];
    filters: { search: string };
    /** Roles this person may hand out: never Owner, never one that grants more than they hold. */
    assignableRoles: RoleOption[];
    /** Every branch and department of the workspace, by name. */
    branches: UnitOption[];
    departments: UnitOption[];
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const { can } = useAccess();

const workspace = computed(() => page.props.tenant?.name ?? '');
const canInvite = computed(() => can('core.members.invite'));
const canManage = computed(() => can('core.members.manage'));

/*
 * Branches and departments. A workspace that has neither gets no columns and
 * no way to place anyone - only, for someone who may add them, the way there.
 */
const hasBranches = computed(() => props.branches.length > 0);
const hasDepartments = computed(() => props.departments.length > 0);
const hasStructure = computed(() => hasBranches.value || hasDepartments.value);
const organizationUrl = computed(() =>
    can('core.organization.view') && can('core.organization.manage') ? route('core.organization.index') : null,
);

/*
 * Search: the list is filtered on the server, a moment after typing stops.
 */
const search = ref(props.filters.search);
const searchInput = ref<InstanceType<typeof Input> | null>(null);
const requestsInFlight = ref(0);
const searching = computed(() => requestsInFlight.value > 0);

// What the server was last asked for, which can be ahead of `filters.search`.
let requested = props.filters.search;
let debounce: number | undefined;

function runSearch(): void {
    window.clearTimeout(debounce);

    const term = search.value.trim();

    if (term === requested) {
        return;
    }

    requested = term;

    router.get(route('core.members.index'), term === '' ? {} : { search: term }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['members', 'filters'],
        onStart: () => {
            requestsInFlight.value += 1;
        },
        onFinish: () => {
            requestsInFlight.value = Math.max(0, requestsInFlight.value - 1);
        },
    });
}

watch(search, () => {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(runSearch, 300);
});

onBeforeUnmount(() => window.clearTimeout(debounce));

function clearSearch(): void {
    search.value = '';
    runSearch();
    searchInput.value?.focus();
}

// A very long query would push the empty state out of its card.
const shownQuery = computed(() => {
    const query = props.filters.search;

    return query.length > 40 ? `${query.slice(0, 40)}…` : query;
});

const peopleCount = computed(() =>
    t(props.members.total === 1 ? 'team.members.people.one' : 'team.members.people.other', {
        count: format.number(props.members.total),
    }),
);

/*
 * Removing the last person on the last page leaves the list on a page that no
 * longer exists. Step back to the one that does.
 */
function leaveEmptyPage(): void {
    const { data, current_page: current, last_page: last } = props.members;

    if (data.length > 0 || current <= 1) {
        return;
    }

    router.get(
        route('core.members.index'),
        {
            ...(props.filters.search === '' ? {} : { search: props.filters.search }),
            ...(last > 1 ? { page: last } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

onMounted(leaveEmptyPage);
watch(() => props.members, leaveEmptyPage);

/*
 * Branch and department start out visible only where the table has room for
 * them: on anything narrower they would push the row actions off the edge, and
 * on a phone they would double the sideways scrolling. The Columns menu turns
 * them on anywhere, and the choice is remembered on that device.
 */
const roomy = typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 90rem)').matches;

const columns = computed<Column[]>(() => {
    const list: Column[] = [
        // Name and role share whatever width is left; the rest are as wide as what they hold.
        { key: 'name', label: t('team.members.columns.member') },
        { key: 'role', label: t('team.members.columns.role') },
    ];

    if (hasBranches.value) {
        list.push({ key: 'branch', label: t('team.members.columns.branch'), class: 'w-px', hideable: true, hiddenByDefault: !roomy });
    }

    if (hasDepartments.value) {
        list.push({
            key: 'department',
            label: t('team.members.columns.department'),
            class: 'w-px',
            hideable: true,
            hiddenByDefault: !roomy,
        });
    }

    list.push(
        { key: 'status', label: t('team.members.columns.status'), class: 'w-px' },
        { key: 'twoFactorEnabled', label: t('team.members.columns.twoFactor'), class: 'w-px', hideable: true },
        // Off until asked for: with it, the row actions fall off the edge of a 1280px screen.
        { key: 'joinedAt', label: t('team.members.columns.joined'), class: 'w-px', hideable: true, hiddenByDefault: true },
        { key: 'lastSeenAt', label: t('team.members.columns.lastSeen'), class: 'w-px', hideable: true },
    );

    if (canManage.value) {
        list.push({ key: 'actions', label: t('team.members.columns.actions'), srOnly: true, align: 'right', class: 'w-px' });
    }

    return list;
});

/*
 * Row actions. The owner and the person themselves never get a menu: the
 * server refuses both, to prevent lock-outs and self-promotion.
 */
const actionsSelector = (member: Member | null | undefined): string | null =>
    member ? `[data-member-actions="${member.id}"]` : null;

// Change role
const roleTarget = ref<Member | null>(null);
const roleOpen = ref(false);

function openChangeRole(member: Member): void {
    roleTarget.value = member;
    roleOpen.value = true;
}

useFocusReturn(roleOpen, () => actionsSelector(roleTarget.value));

// Branch and department. The owner is the one person who gets this for their own row.
const placementTarget = ref<Member | null>(null);
const placementOpen = ref(false);

function openPlacement(member: Member): void {
    placementTarget.value = member;
    placementOpen.value = true;
}

useFocusReturn(placementOpen, () => actionsSelector(placementTarget.value));

// Suspend and remove share one confirmation dialog.
const pending = ref<{ kind: 'suspend' | 'remove'; member: Member } | null>(null);
const confirmOpen = ref(false);
const confirming = ref(false);
const confirmError = ref<string | null>(null);

function ask(kind: 'suspend' | 'remove', member: Member): void {
    pending.value = { kind, member };
    confirmError.value = null;
    confirmOpen.value = true;
}

useFocusReturn(confirmOpen, () => actionsSelector(pending.value?.member));

const confirmCopy = computed(() => {
    const action = pending.value;

    if (!action) {
        return { title: '', body: '', confirm: '' };
    }

    const names = { name: action.member.name, email: action.member.email, workspace: workspace.value };

    return {
        title: t(`team.${action.kind}.title`, names),
        body: t(`team.${action.kind}.body`, names),
        confirm: t(`team.${action.kind}.confirm`),
    };
});

function confirm(): void {
    const action = pending.value;

    if (!action) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
        onError: (errors: Record<string, string>) => {
            confirmError.value = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            confirming.value = false;
        },
    };

    confirming.value = true;
    confirmError.value = null;

    if (action.kind === 'remove') {
        router.delete(route('core.members.destroy', { membership: action.member.id }), options);
    } else {
        router.patch(route('core.members.update', { membership: action.member.id }), { status: 'suspended' }, options);
    }
}

// Reactivating gives access back, so it needs no confirmation.
const reactivating = ref<string | null>(null);

function reactivate(member: Member): void {
    reactivating.value = member.id;

    router.patch(
        route('core.members.update', { membership: member.id }),
        { status: 'active' },
        {
            preserveScroll: true,
            onFinish: () => {
                reactivating.value = null;

                // The menu button was disabled while saving and dropped its focus.
                void nextTick(() => {
                    const selector = actionsSelector(member);

                    if (selector && document.activeElement === document.body) {
                        document.querySelector<HTMLElement>(selector)?.focus();
                    }
                });
            },
        },
    );
}

/*
 * Invitations
 */
const inviteOpen = ref(false);
const invitePrefill = ref<{ email: string; role: string } | null>(null);

function openInvite(invitation?: OpenInvitation): void {
    invitePrefill.value = invitation ? { email: invitation.email, role: invitation.role } : null;
    inviteOpen.value = true;
}

useFocusReturn(inviteOpen);
</script>

<template>
    <PageHeader
        :eyebrow="t('shell.nav.sections.manage')"
        :title="t('team.members.title')"
        :description="t('team.members.description', { workspace })"
    >
        <template v-if="canInvite" #actions>
            <Button variant="primary" @click="openInvite()">
                <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                {{ t('team.members.invite') }}
            </Button>
        </template>
    </PageHeader>

    <div class="flex flex-col gap-3">
        <Card flush :aria-busy="searching || undefined">
            <DataTable
                :columns="columns"
                :rows="members.data"
                row-key="id"
                :caption="t('team.members.caption')"
                storage-key="members"
                class="pt-4"
            >
                <template #toolbar>
                    <div class="w-full sm:w-72">
                        <Input
                            ref="searchInput"
                            v-model="search"
                            role="searchbox"
                            inputmode="search"
                            enterkeyhint="search"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="100"
                            :aria-label="t('team.members.search')"
                            :placeholder="t('team.members.search')"
                            @keydown.enter.prevent="runSearch"
                        >
                            <template #leading><Search class="size-4" aria-hidden="true" /></template>
                            <template v-if="searching || search !== ''" #trailing>
                                <Spinner v-if="searching" class="mr-2 text-ink-subtle" />
                                <Button v-else icon size="sm" variant="ghost" @click="clearSearch">
                                    <template #icon><X class="size-4" aria-hidden="true" /></template>
                                    {{ t('team.members.clearSearch') }}
                                </Button>
                            </template>
                        </Input>
                    </div>
                    <p class="eyebrow tabular px-1" role="status">{{ peopleCount }}</p>
                </template>

                <template #cell-name="{ row }">
                    <!-- Asks the column for 12rem and then fills whatever it gets, so long names truncate instead of widening the table. -->
                    <div class="flex w-48 min-w-full items-center gap-3">
                        <Avatar :initials="row.initials" :name="row.email" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium text-ink" :title="row.name">{{ row.name }}</span>
                                <Badge v-if="row.isSelf">{{ t('common.you') }}</Badge>
                            </p>
                            <p class="truncate text-[0.8125rem] text-ink-subtle" :title="row.email">{{ row.email }}</p>
                        </div>
                    </div>
                </template>

                <template #cell-role="{ row }">
                    <Badge v-if="row.isOwner" tone="brand">{{ t('team.members.owner') }}</Badge>
                    <span v-else-if="row.role" class="block w-24 min-w-full truncate text-ink" :title="row.role.name">
                        {{ row.role.name }}
                    </span>
                    <span v-else class="whitespace-nowrap text-ink-subtle">{{ t('team.members.noRole') }}</span>
                </template>

                <template #cell-branch="{ row }">
                    <span v-if="row.branch" class="block max-w-36 truncate text-ink-muted" :title="row.branch.name">{{ row.branch.name }}</span>
                    <span v-else class="text-ink-subtle">
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">{{ t('team.placement.noBranch') }}</span>
                    </span>
                </template>

                <template #cell-department="{ row }">
                    <span v-if="row.department" class="block max-w-36 truncate text-ink-muted" :title="row.department.name">
                        {{ row.department.name }}
                    </span>
                    <span v-else class="text-ink-subtle">
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">{{ t('team.placement.noDepartment') }}</span>
                    </span>
                </template>

                <!-- Active is the normal case and stays quiet; a suspension is what should catch the eye. -->
                <template #cell-status="{ row }">
                    <Badge v-if="row.status === 'suspended'" tone="warn" dot>{{ t('common.status.suspended') }}</Badge>
                    <span v-else class="inline-flex items-center gap-1.5 whitespace-nowrap text-ink-muted">
                        <span class="size-1.5 rounded-full bg-ok" aria-hidden="true" />
                        {{ t('common.status.active') }}
                    </span>
                </template>

                <template #cell-twoFactorEnabled="{ row }">
                    <span
                        v-if="row.twoFactorEnabled"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap text-ink"
                        :title="t('team.members.twoFactorOn')"
                    >
                        <ShieldCheck class="size-4 text-ok" aria-hidden="true" />
                        {{ t('common.status.enabled') }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 whitespace-nowrap text-ink-subtle"
                        :title="t('team.members.twoFactorOff')"
                    >
                        <ShieldOff class="size-4" aria-hidden="true" />
                        {{ t('common.status.disabled') }}
                    </span>
                </template>

                <template #cell-joinedAt="{ row }">
                    <time v-if="row.joinedAt" :datetime="row.joinedAt" class="tabular whitespace-nowrap text-ink-muted">
                        {{ format.date(row.joinedAt) }}
                    </time>
                    <span v-else class="text-ink-subtle">—</span>
                </template>

                <template #cell-lastSeenAt="{ row }">
                    <time
                        v-if="row.lastSeenAt"
                        :datetime="row.lastSeenAt"
                        :title="format.date(row.lastSeenAt, 'datetime')"
                        class="tabular whitespace-nowrap text-ink-muted"
                    >
                        {{ format.relative(row.lastSeenAt) }}
                    </time>
                    <span v-else class="whitespace-nowrap text-ink-subtle">{{ t('common.never') }}</span>
                </template>

                <template #cell-actions="{ row }">
                    <!-- The owner's own row has one entry at most: where they sit. -->
                    <Dropdown v-if="row.manageable || (row.placeable && hasStructure)">
                        <template #trigger>
                            <Button icon size="sm" variant="ghost" :loading="reactivating === row.id" :data-member-actions="row.id">
                                <template #icon><Ellipsis class="size-4" aria-hidden="true" /></template>
                                {{ t('team.members.actions.menu', { name: row.name }) }}
                            </Button>
                        </template>

                        <DropdownItem v-if="row.manageable && assignableRoles.length > 0" @select="openChangeRole(row)">
                            <template #icon><UserCog aria-hidden="true" /></template>
                            {{ t('team.members.actions.changeRole') }}
                        </DropdownItem>
                        <DropdownItem v-if="row.placeable && hasStructure" @select="openPlacement(row)">
                            <template #icon><Network aria-hidden="true" /></template>
                            {{ t('team.members.actions.place') }}
                        </DropdownItem>
                        <template v-if="row.manageable">
                            <DropdownItem v-if="row.status === 'suspended'" @select="reactivate(row)">
                                <template #icon><CirclePlay aria-hidden="true" /></template>
                                {{ t('team.members.actions.reactivate') }}
                            </DropdownItem>
                            <DropdownItem v-else @select="ask('suspend', row)">
                                <template #icon><CirclePause aria-hidden="true" /></template>
                                {{ t('team.members.actions.suspend') }}
                            </DropdownItem>
                            <DropdownSeparator />
                            <DropdownItem tone="danger" @select="ask('remove', row)">
                                <template #icon><UserMinus aria-hidden="true" /></template>
                                {{ t('team.members.actions.remove') }}
                            </DropdownItem>
                        </template>
                    </Dropdown>
                </template>

                <template #empty>
                    <EmptyState
                        v-if="filters.search !== ''"
                        :title="t('team.members.empty.searchTitle', { query: shownQuery })"
                        :description="t('team.members.empty.searchBody')"
                    >
                        <template #icon><SearchX /></template>
                        <Button size="sm" @click="clearSearch">{{ t('team.members.clearSearch') }}</Button>
                    </EmptyState>
                    <EmptyState v-else :title="t('team.members.empty.title')" :description="t('team.members.empty.body')">
                        <template #icon><Users /></template>
                    </EmptyState>
                </template>
            </DataTable>

            <Pagination :meta="members" />

            <!-- Nowhere to place anyone yet: point the people who can change that to where it is done. -->
            <template v-if="canManage && !hasStructure && organizationUrl" #footer>
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <p class="flex min-w-0 items-start gap-2 text-[0.8125rem] text-ink-subtle">
                        <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        {{ t('team.members.organize.body') }}
                    </p>
                    <Button size="sm" :href="organizationUrl">
                        <template #icon><Network class="size-3.5" aria-hidden="true" /></template>
                        {{ t('team.members.organize.link') }}
                    </Button>
                </div>
            </template>
        </Card>

        <InvitationList
            v-if="invitations.length > 0 || canInvite"
            :invitations="invitations"
            :can-resend="canInvite && assignableRoles.length > 0"
            @resend="openInvite"
        />
    </div>

    <ChangeRoleDialog v-model:open="roleOpen" :member="roleTarget" :roles="assignableRoles" />

    <PlacementDialog
        v-if="canManage && hasStructure"
        v-model:open="placementOpen"
        :member="placementTarget"
        :branches="branches"
        :departments="departments"
        :organization-url="organizationUrl"
    />

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="confirmCopy.title"
        :description="confirmCopy.body"
        :confirm-label="confirmCopy.confirm"
        :loading="confirming"
        @confirm="confirm"
    >
        <p v-if="confirmError" class="text-[0.8125rem] text-danger" role="alert">{{ confirmError }}</p>
    </ConfirmDialog>

    <InviteMemberDialog v-if="canInvite" v-model:open="inviteOpen" :roles="assignableRoles" :prefill="invitePrefill" />
</template>

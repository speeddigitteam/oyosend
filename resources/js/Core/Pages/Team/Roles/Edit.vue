<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert, KeyRound, Lock, ShieldCheck } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import PermissionGroup from '@/Core/Components/Team/PermissionGroup.vue';
import { isChangeable, savedScopes } from '@/Core/Components/Team/scopes';
import type { AccessScope, CatalogueModule, CataloguePermission, EditableRole, ScopeMap } from '@/Core/Components/Team/types';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { moduleLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    /** `null` when creating a role. */
    role: EditableRole | null;
    /** Every permission a role in this workspace can carry, by module and group. */
    catalogue: CatalogueModule[];
}>();

const { t } = useI18n();
const format = useFormat();

/** The Owner role is shown for reference only; the server refuses any change to it. */
const isOwner = computed(() => props.role?.isOwner === true);
/** Other code refers to system roles by name, so the name stays. */
const nameLocked = computed(() => props.role?.isSystem === true);

/** How far each grant of the saved role reaches. They also decide which grants this person may touch. */
const savedScopeMap = computed<ScopeMap>(() => savedScopes(props.role));

const form = useForm<{ name: string; description: string; permissions: string[]; scopes: ScopeMap }>({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permissions: [...(props.role?.permissions ?? [])],
    // Sent back as it came for every grant left alone: a scope that is missing means "every record" to the server.
    scopes: { ...savedScopeMap.value },
});

const nameInput = ref<InstanceType<typeof Input> | null>(null);

const everyPermission = computed(() => props.catalogue.flatMap((module) => module.groups.flatMap((group) => group.permissions)));
const byName = computed(() => new Map(everyPermission.value.map((permission) => [permission.name, permission])));
const total = computed(() => everyPermission.value.length);

// The Owner role stores no grants, yet holds all of them.
const selected = computed(() => new Set(isOwner.value ? everyPermission.value.map((permission) => permission.name) : form.permissions));

const selectedCount = computed(() => everyPermission.value.filter((permission) => selected.value.has(permission.name)).length);
const sensitiveCount = computed(
    () => everyPermission.value.filter((permission) => permission.sensitive && selected.value.has(permission.name)).length,
);
// What this person cannot change: permissions they do not hold, and grants that reach further than their own.
const lockedCount = computed(
    () => everyPermission.value.filter((permission) => !isChangeable(permission, savedScopeMap.value[permission.name])).length,
);
const hasScopable = computed(() => everyPermission.value.some((permission) => permission.scopable));

/** A permission ticked again goes back to the scope it was saved with; a new one reaches as far as this person may grant it. */
function startingScope(permission: CataloguePermission): AccessScope {
    return savedScopeMap.value[permission.name] ?? permission.maxScope ?? 'all';
}

function toggle(names: string[], on: boolean): void {
    const next = new Set(form.permissions);
    const scopes = { ...form.scopes };

    for (const name of names) {
        if (!on) {
            next.delete(name);
            delete scopes[name];

            continue;
        }

        if (next.has(name)) {
            continue;
        }

        next.add(name);

        const permission = byName.value.get(name);

        if (permission?.scopable) {
            scopes[name] = startingScope(permission);
        }
    }

    form.permissions = [...next];
    form.scopes = scopes;
    form.clearErrors('permissions', 'scopes');
}

function setScope(name: string, scope: AccessScope): void {
    form.scopes = { ...form.scopes, [name]: scope };
    form.clearErrors('permissions', 'scopes');
}

function moduleCount(module: CatalogueModule): string {
    const permissions = module.groups.flatMap((group) => group.permissions);

    return t('roles.editor.moduleCount', {
        count: format.number(permissions.filter((permission) => selected.value.has(permission.name)).length),
        total: format.number(permissions.length),
    });
}

/*
 * What differs from the saved role. Compared as sets, so ticking a box and
 * unticking it again leaves nothing to save.
 */
const saved = computed(() => new Set(props.role?.permissions ?? []));
const added = computed(() => form.permissions.filter((name) => !saved.value.has(name)).length);
const removed = computed(() => [...saved.value].filter((name) => !selected.value.has(name)).length);
// Grants that stay, but reach further or less far than they did.
const rescoped = computed(
    () =>
        form.permissions.filter(
            (name) =>
                saved.value.has(name) &&
                byName.value.get(name)?.scopable === true &&
                (form.scopes[name] ?? 'all') !== (savedScopeMap.value[name] ?? 'all'),
        ).length,
);
const detailsChanged = computed(
    () => form.name !== (props.role?.name ?? '') || form.description !== (props.role?.description ?? ''),
);
const grantsChanged = computed(() => added.value > 0 || removed.value > 0 || rescoped.value > 0);
const dirty = computed(() => props.role === null || detailsChanged.value || grantsChanged.value);

const notes = computed(() => {
    const parts: string[] = [];

    if (props.role !== null && !isOwner.value) {
        if (added.value > 0) {
            parts.push(t('roles.editor.summary.added', { count: format.number(added.value) }));
        }

        if (removed.value > 0) {
            parts.push(t('roles.editor.summary.removed', { count: format.number(removed.value) }));
        }

        if (rescoped.value > 0) {
            parts.push(t('roles.editor.summary.rescoped', { count: format.number(rescoped.value) }));
        }

        if (detailsChanged.value && !grantsChanged.value) {
            parts.push(t('roles.editor.summary.unsaved'));
        }
    }

    if (sensitiveCount.value > 0) {
        parts.push(t('roles.editor.summary.sensitive', { count: format.number(sensitiveCount.value) }));
    }

    return parts.join(' · ');
});

// "The Owner role cannot be changed" and similar arrive under a key that is not a form field.
const roleError = computed(() => (form.errors as Record<string, string | undefined>).role);
// The scopes are validated as one map, so their error has no field of its own to sit under.
const grantError = computed(
    () => form.errors.permissions ?? (form.errors as Record<string, string | undefined>).scopes ?? roleError.value,
);

function submit(): void {
    if (isOwner.value) {
        return;
    }

    // Only the scopes of what is ticked; the server reads a missing one as "every record".
    form.transform((data) => ({
        ...data,
        scopes: Object.fromEntries(Object.entries(data.scopes).filter(([name]) => data.permissions.includes(name))),
    }));

    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) => {
            // The save bar can be far below the field that needs fixing.
            if (errors.name) {
                void nextTick(() => nameInput.value?.focus());
            }
        },
    };

    if (props.role) {
        form.put(route('core.roles.update', { role: props.role.id }), options);
    } else {
        form.post(route('core.roles.store'), options);
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('roles.editor.eyebrow')"
        :title="role ? t('roles.editor.editTitle', { name: role.name }) : t('roles.editor.createTitle')"
        :description="isOwner ? undefined : role ? t('roles.editor.editDescription') : t('roles.editor.createDescription')"
    >
        <template #actions>
            <Button variant="ghost" :href="route('core.roles.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('roles.editor.back') }}
            </Button>
        </template>
    </PageHeader>

    <form class="flex flex-col gap-3" novalidate @submit.prevent="submit">
        <Card v-if="isOwner">
            <div class="flex items-start gap-4">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand-text" aria-hidden="true">
                    <ShieldCheck class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-ink">{{ t('roles.editor.owner.title') }}</p>
                    <p class="mt-1 max-w-2xl text-[0.8125rem] text-ink-muted">{{ t('roles.editor.owner.body') }}</p>
                </div>
            </div>
        </Card>

        <Card :title="t('roles.editor.details')">
            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <Input
                    ref="nameInput"
                    v-model="form.name"
                    name="name"
                    :label="t('roles.editor.name')"
                    :error="form.errors.name"
                    :hint="nameLocked && !isOwner ? t('roles.editor.systemName') : undefined"
                    :disabled="nameLocked"
                    maxlength="80"
                    autocomplete="off"
                    required
                />
                <Input
                    v-model="form.description"
                    name="description"
                    :label="t('roles.editor.description')"
                    :error="form.errors.description"
                    :hint="isOwner ? undefined : t('roles.editor.descriptionHint')"
                    :disabled="isOwner"
                    :optional="!isOwner"
                    maxlength="255"
                    autocomplete="off"
                />
            </div>
        </Card>

        <div class="mt-4 px-1">
            <h2 class="text-lg text-ink">{{ t('roles.editor.permissions') }}</h2>
            <template v-if="!isOwner">
                <p class="mt-1 max-w-2xl text-[0.8125rem] text-ink-muted">{{ t('roles.editor.permissionsHelp') }}</p>
                <p v-if="hasScopable" class="mt-1 max-w-2xl text-[0.8125rem] text-ink-muted">{{ t('roles.editor.scopesHelp') }}</p>
                <p v-if="lockedCount > 0" class="mt-2 flex max-w-2xl items-start gap-2 text-[0.8125rem] text-ink-muted">
                    <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    {{
                        t(lockedCount === 1 ? 'roles.editor.locked.one' : 'roles.editor.locked.other', {
                            count: format.number(lockedCount),
                        })
                    }}
                </p>
            </template>
        </div>

        <Card v-for="module in catalogue" :key="module.module" :title="moduleLabel(module.module, module.name)">
            <template #actions>
                <span class="eyebrow tabular">{{ moduleCount(module) }}</span>
            </template>

            <div class="divide-y divide-line">
                <PermissionGroup
                    v-for="group in module.groups"
                    :key="group.key"
                    :group="group"
                    :selected="selected"
                    :scopes="form.scopes"
                    :saved="savedScopeMap"
                    :readonly="isOwner"
                    @toggle="toggle"
                    @scope="setScope"
                />
            </div>
        </Card>

        <Card v-if="catalogue.length === 0">
            <EmptyState :title="t('roles.editor.emptyCatalogue')" compact>
                <template #icon><KeyRound /></template>
            </EmptyState>
        </Card>

        <!-- Stays in view while the catalogue scrolls: the running count, and the way out. -->
        <div class="sticky bottom-3 z-10 mt-1">
            <div class="glass-solid rounded-panel px-5 py-3.5">
                <p v-if="grantError" class="mb-3 flex items-start gap-2 text-[0.8125rem] text-danger" role="alert">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    {{ grantError }}
                </p>

                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <p class="flex min-w-0 items-center gap-3.5" role="status">
                        <span class="figure text-[2rem] text-ink">{{ format.number(selectedCount) }}</span>
                        <span class="min-w-0">
                            <span class="block text-sm text-ink">
                                {{
                                    isOwner
                                        ? t('roles.editor.summary.ownerAll')
                                        : t('roles.editor.summary.selected', { total: format.number(total) })
                                }}
                            </span>
                            <span v-if="notes" class="block text-[0.8125rem] text-ink-subtle">{{ notes }}</span>
                        </span>
                    </p>

                    <div class="ml-auto flex shrink-0 items-center gap-2">
                        <Button variant="ghost" :href="route('core.roles.index')">
                            {{ isOwner ? t('roles.editor.back') : t('common.actions.cancel') }}
                        </Button>
                        <Button v-if="!isOwner" type="submit" variant="primary" :loading="form.processing" :disabled="!dirty">
                            {{ role ? t('common.actions.save') : t('roles.editor.create') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</template>

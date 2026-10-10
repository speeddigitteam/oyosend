<script setup lang="ts">
import { Check, Lock } from 'lucide-vue-next';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { permissionDescription, permissionGroupLabel, permissionLabel, scopeDescription, scopeLabel } from '@/Core/lib/labels';
import { isChangeable, SCOPES, scopesUpTo } from './scopes';
import type { AccessScope, CatalogueGroup, CataloguePermission, ScopeMap } from './types';

/**
 * One group of the permission catalogue in the role editor: a "select all"
 * for the group beside the permissions themselves.
 *
 * A permission the editor does not hold (`grantable: false`) is locked in
 * whatever state the role has it - the server rejects both granting and
 * removing it - and "select all" leaves it alone. The same goes for a grant
 * that reaches further than the editor's own.
 *
 * A ticked permission that can be narrowed (`scopable`) also shows how far it
 * reaches, and lets the editor choose that up to the scope they hold.
 */
const props = withDefaults(
    defineProps<{
        group: CatalogueGroup;
        /** Names of the permissions currently ticked. */
        selected: Set<string>;
        /** The scope chosen for each ticked permission; one that is not named reaches every record. */
        scopes?: ScopeMap;
        /** The scopes the role was saved with. They decide which grants the editor may touch. */
        saved?: ScopeMap;
        /** Show the group as a plain list, with nothing to change. */
        readonly?: boolean;
    }>(),
    { scopes: () => ({}), saved: () => ({}) },
);

const emit = defineEmits<{
    toggle: [names: string[], on: boolean];
    scope: [name: string, scope: AccessScope];
}>();

const { t } = useI18n();
const format = useFormat();
const headingId = useId();

function changeable(permission: CataloguePermission): boolean {
    return isChangeable(permission, props.saved[permission.name]);
}

/** Why a permission cannot be changed, for the people who cannot change it. */
function lockReason(permission: CataloguePermission): string | null {
    if (!permission.grantable) {
        return t('roles.editor.lockedItem');
    }

    return changeable(permission) ? null : t('roles.editor.lockedWider');
}

const total = computed(() => props.group.permissions.length);
const count = computed(() => props.group.permissions.filter((permission) => props.selected.has(permission.name)).length);
const changeableNames = computed(() => props.group.permissions.filter(changeable).map((permission) => permission.name));

/*
 * The box is ticked when everything that can be changed is ticked, so every
 * click flips it and it never disagrees with the native checkbox underneath.
 * The dash still shows whenever the group as a whole is only partly granted.
 */
const allOn = computed(() =>
    changeableNames.value.length > 0 ? changeableNames.value.every((name) => props.selected.has(name)) : count.value === total.value,
);
const partial = computed(() => count.value > 0 && count.value < total.value);

const countText = computed(() =>
    t('roles.editor.groupCount', { count: format.number(count.value), total: format.number(total.value) }),
);

/*
 * Scopes
 */
function scoped(permission: CataloguePermission): boolean {
    return permission.scopable && props.selected.has(permission.name);
}

function scopeOf(permission: CataloguePermission): AccessScope {
    return props.scopes[permission.name] ?? 'all';
}

/** A choice exists only where the editor may touch the grant and holds more than the narrowest scope. */
function rescopable(permission: CataloguePermission): boolean {
    return !props.readonly && changeable(permission) && scopesUpTo(permission.maxScope).length > 1;
}

function scopeOptions(permission: CataloguePermission): SelectOption[] {
    return scopesUpTo(permission.maxScope).map((scope) => ({ value: scope, label: scopeLabel(scope) }));
}

/** Said where "All" is missing from the choices, so its absence is not a puzzle. */
function scopeLimit(permission: CataloguePermission): string | null {
    if (props.readonly || !changeable(permission) || permission.maxScope === null || permission.maxScope === 'all') {
        return null;
    }

    return t('roles.editor.scopeLimited', { scope: scopeLabel(permission.maxScope) });
}

function scopeHint(permission: CataloguePermission): string {
    return [scopeDescription(scopeOf(permission)), scopeLimit(permission)].filter(Boolean).join(' ');
}

function chooseScope(permission: CataloguePermission, value: unknown): void {
    const scope = SCOPES.find((candidate) => candidate === value);

    if (scope !== undefined) {
        emit('scope', permission.name, scope);
    }
}
</script>

<template>
    <div
        role="group"
        :aria-labelledby="headingId"
        class="grid gap-x-8 gap-y-4 py-5 first:pt-1 last:pb-0 lg:grid-cols-[13.5rem_minmax(0,1fr)]"
    >
        <div v-if="readonly">
            <p :id="headingId" class="text-sm font-semibold text-ink">{{ permissionGroupLabel(group.key) }}</p>
            <p class="tabular mt-0.5 text-[0.8125rem] text-ink-subtle">{{ countText }}</p>
        </div>
        <div v-else>
            <Checkbox
                :model-value="allOn"
                :indeterminate="partial"
                :disabled="changeableNames.length === 0"
                :description="countText"
                @update:model-value="emit('toggle', changeableNames, $event)"
            >
                <span :id="headingId" class="font-semibold">{{ permissionGroupLabel(group.key) }}</span>
                <span class="sr-only">, {{ t('roles.editor.selectAll') }}</span>
            </Checkbox>
        </div>

        <ul class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
            <li v-for="permission in group.permissions" :key="permission.name" class="min-w-0">
                <div v-if="readonly" class="flex items-start gap-2.5">
                    <span
                        class="mt-px flex size-[1.125rem] shrink-0 items-center justify-center rounded-[0.3125rem] bg-brand-soft text-brand-text"
                        aria-hidden="true"
                    >
                        <Check v-if="selected.has(permission.name)" class="size-3" :stroke-width="3.5" />
                    </span>
                    <span class="min-w-0 leading-snug">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink">
                            <span>{{ permissionLabel(permission.name) }}</span>
                            <Badge v-if="permission.sensitive" tone="warn">{{ t('roles.editor.sensitive') }}</Badge>
                            <span v-if="selected.has(permission.name)" class="sr-only">{{ t('roles.editor.granted') }}</span>
                        </span>
                        <span v-if="permissionDescription(permission.name)" class="mt-0.5 block text-[0.8125rem] text-ink-subtle">
                            {{ permissionDescription(permission.name) }}
                        </span>
                    </span>
                </div>

                <template v-else>
                    <Checkbox
                        :model-value="selected.has(permission.name)"
                        :disabled="!changeable(permission)"
                        :description="permissionDescription(permission.name) || undefined"
                        @update:model-value="emit('toggle', [permission.name], $event)"
                    >
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span>{{ permissionLabel(permission.name) }}</span>
                            <Badge v-if="permission.sensitive" tone="warn">{{ t('roles.editor.sensitive') }}</Badge>
                            <span v-if="lockReason(permission)" class="sr-only">{{ lockReason(permission) }}</span>
                        </span>
                    </Checkbox>

                    <!-- Outside the label on purpose: a disabled checkbox is dimmed, its explanation should not be. -->
                    <p
                        v-if="lockReason(permission)"
                        class="mt-1.5 flex items-start gap-1.5 pl-7 text-xs text-ink-muted"
                        aria-hidden="true"
                    >
                        <Lock class="mt-0.5 size-3 shrink-0" />
                        {{ lockReason(permission) }}
                    </p>
                </template>

                <!-- How far the grant reaches. Lines up with the label, past the checkbox. -->
                <div v-if="scoped(permission)" class="mt-2.5 pl-7">
                    <Select
                        v-if="rescopable(permission)"
                        :model-value="scopeOf(permission)"
                        :options="scopeOptions(permission)"
                        :label="t('roles.editor.scope')"
                        :hint="scopeHint(permission)"
                        :aria-label="t('roles.editor.scopeFor', { permission: permissionLabel(permission.name) })"
                        @update:model-value="chooseScope(permission, $event)"
                    />
                    <p v-else class="text-[0.8125rem] text-ink-subtle">
                        <span class="font-medium text-ink-muted">{{ t('roles.editor.scopeIs', { scope: scopeLabel(scopeOf(permission)) }) }}</span>
                        <span aria-hidden="true"> · </span>
                        {{ scopeHint(permission) }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>

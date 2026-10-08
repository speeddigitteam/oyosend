<script setup lang="ts">
import { Check, Lock } from 'lucide-vue-next';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { permissionDescription, permissionGroupLabel, permissionLabel } from '@/Core/lib/labels';
import type { CatalogueGroup } from './types';

/**
 * One group of the permission catalogue in the role editor: a "select all"
 * for the group beside the permissions themselves.
 *
 * A permission the editor does not hold (`grantable: false`) is locked in
 * whatever state the role has it - the server rejects both granting and
 * removing it - and "select all" leaves it alone.
 */
const props = defineProps<{
    group: CatalogueGroup;
    /** Names of the permissions currently ticked. */
    selected: Set<string>;
    /** Show the group as a plain list, with nothing to change. */
    readonly?: boolean;
}>();

const emit = defineEmits<{ toggle: [names: string[], on: boolean] }>();

const { t } = useI18n();
const format = useFormat();
const headingId = useId();

const total = computed(() => props.group.permissions.length);
const count = computed(() => props.group.permissions.filter((permission) => props.selected.has(permission.name)).length);
const changeable = computed(() => props.group.permissions.filter((permission) => permission.grantable).map((permission) => permission.name));

/*
 * The box is ticked when everything that can be changed is ticked, so every
 * click flips it and it never disagrees with the native checkbox underneath.
 * The dash still shows whenever the group as a whole is only partly granted.
 */
const allOn = computed(() =>
    changeable.value.length > 0 ? changeable.value.every((name) => props.selected.has(name)) : count.value === total.value,
);
const partial = computed(() => count.value > 0 && count.value < total.value);

const countText = computed(() =>
    t('roles.editor.groupCount', { count: format.number(count.value), total: format.number(total.value) }),
);
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
                :disabled="changeable.length === 0"
                :description="countText"
                @update:model-value="emit('toggle', changeable, $event)"
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
                        :disabled="!permission.grantable"
                        :description="permissionDescription(permission.name) || undefined"
                        @update:model-value="emit('toggle', [permission.name], $event)"
                    >
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span>{{ permissionLabel(permission.name) }}</span>
                            <Badge v-if="permission.sensitive" tone="warn">{{ t('roles.editor.sensitive') }}</Badge>
                            <span v-if="!permission.grantable" class="sr-only">{{ t('roles.editor.lockedItem') }}</span>
                        </span>
                    </Checkbox>

                    <!-- Outside the label on purpose: a disabled checkbox is dimmed, its explanation should not be. -->
                    <p
                        v-if="!permission.grantable"
                        class="mt-1.5 flex items-start gap-1.5 pl-7 text-xs text-ink-muted"
                        aria-hidden="true"
                    >
                        <Lock class="mt-0.5 size-3 shrink-0" />
                        {{ t('roles.editor.lockedItem') }}
                    </p>
                </template>
            </li>
        </ul>
    </div>
</template>

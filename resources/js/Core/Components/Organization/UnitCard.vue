<script setup lang="ts" generic="T extends OrganizationUnit">
import { MapPin, Pencil, Plus, Trash2, Users } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import type { OrganizationUnit, UnitDetail, UnitKind } from './types';

/**
 * The branches of the workspace, or its departments: one card, one row each,
 * with how many people sit there. Its texts are read from
 * `organization.{kind}`.
 *
 * The card only asks; the page opens the dialogs and talks to the server.
 */
defineProps<{
    kind: UnitKind;
    units: T[];
    /** Offer adding, editing and deleting. */
    canManage: boolean;
    /** The lines under a unit's name: a phone number, an address, a description. */
    details: (unit: T) => UnitDetail[];
}>();

defineEmits<{
    add: [];
    edit: [unit: T];
    remove: [unit: T];
}>();

const { t } = useI18n();
const format = useFormat();

function membersText(members: number): string {
    if (members === 0) {
        return t('organization.members.none');
    }

    return t(members === 1 ? 'organization.members.one' : 'organization.members.other', { count: format.number(members) });
}
</script>

<template>
    <Card :title="t(`organization.${kind}.title`)" :description="t(`organization.${kind}.description`)" flush>
        <template v-if="canManage && units.length > 0" #actions>
            <Button size="sm" @click="$emit('add')">
                <template #icon><Plus class="size-3.5" aria-hidden="true" /></template>
                {{ t(`organization.${kind}.add`) }}
            </Button>
        </template>

        <EmptyState
            v-if="units.length === 0"
            :title="t(`organization.${kind}.empty.title`)"
            :description="t(canManage ? `organization.${kind}.empty.body` : `organization.${kind}.empty.bodyViewOnly`)"
            compact
        >
            <template #icon>
                <MapPin v-if="kind === 'branches'" />
                <Users v-else />
            </template>
            <Button v-if="canManage" size="sm" variant="primary" @click="$emit('add')">
                <template #icon><Plus class="size-3.5" aria-hidden="true" /></template>
                {{ t(`organization.${kind}.add`) }}
            </Button>
        </EmptyState>

        <ul v-else>
            <li
                v-for="unit in units"
                :key="unit.id"
                :data-unit="unit.id"
                class="flex flex-col gap-x-5 gap-y-2 border-t border-line px-5 py-3.5 transition-colors hover:bg-hover sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="min-w-0 text-sm font-semibold wrap-break-word text-ink">{{ unit.name }}</span>
                        <Badge v-if="unit.code" class="font-mono">
                            <span class="sr-only">{{ t('organization.form.code') }}: </span>
                            {{ unit.code }}
                        </Badge>
                    </p>

                    <ul v-if="details(unit).length > 0" class="mt-1 flex flex-col gap-0.5 text-[0.8125rem] text-ink-muted">
                        <li v-for="(detail, index) in details(unit)" :key="index" class="flex items-start gap-1.5">
                            <component :is="detail.icon" v-if="detail.icon" class="mt-0.5 size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                            <span v-if="detail.label" class="sr-only">{{ detail.label }}: </span>
                            <span class="min-w-0 wrap-break-word">{{ detail.text }}</span>
                        </li>
                    </ul>
                </div>

                <div class="flex shrink-0 items-center justify-between gap-3 sm:justify-end">
                    <span :class="['tabular text-[0.8125rem] whitespace-nowrap', unit.members > 0 ? 'text-ink-muted' : 'text-ink-subtle']">
                        {{ membersText(unit.members) }}
                    </span>

                    <span v-if="canManage" class="flex items-center gap-1">
                        <Button icon size="sm" variant="ghost" @click="$emit('edit', unit)">
                            <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                            {{ t(`organization.${kind}.edit`, { name: unit.name }) }}
                        </Button>
                        <Button icon size="sm" variant="ghost" @click="$emit('remove', unit)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t(`organization.${kind}.remove`, { name: unit.name }) }}
                        </Button>
                    </span>
                </div>
            </li>
        </ul>
    </Card>
</template>

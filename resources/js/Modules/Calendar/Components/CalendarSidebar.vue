<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarPlus, Check, ChevronDown, MoreVertical, Plus, UserRoundSearch, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import { route } from '@/Core/lib/route';
import { colorVar } from '../lib/format';
import type { DateRange } from '../lib/dates';
import type { CalendarInfo, Person } from '../types';
import MiniMonth from './MiniMonth.vue';

/**
 * The calendar's own side panel: Create, a month to jump around in, teammates to follow, and every calendar with a
 * switch to show or hide it (remembered for the person, on the server).
 */
const props = defineProps<{
    date: string;
    today: string;
    range: DateRange;
    weekStartsOn: number;
    calendars: CalendarInfo[];
    members: Person[];
    followed: string[];
    canCreate: boolean;
    canCreateShared: boolean;
    /** Show/hide switches flipped but not yet saved, by calendar id. */
    overrides: Record<string, boolean>;
}>();

const emit = defineEmits<{
    pick: [date: string];
    createEvent: [];
    createCalendar: [shared: boolean];
    editCalendar: [calendar: CalendarInfo];
    toggle: [calendar: CalendarInfo, visible: boolean];
}>();

const { t } = useI18n();

const mine = computed(() => props.calendars.filter((calendar) => calendar.mine));
const workspace = computed(() => props.calendars.filter((calendar) => calendar.shared));
const others = computed(() => props.calendars.filter((calendar) => !calendar.mine && !calendar.shared));
const followedPeople = computed(() => props.followed.map((id) => props.members.find((member) => member.id === id)).filter((member): member is Person => Boolean(member)));
const groups = computed(() => [
    { key: 'mine', heading: t('calendar.sidebar.myCalendars'), items: mine.value, add: props.canCreate ? () => emit('createCalendar', false) : null, addLabel: t('calendar.sidebar.addCalendar') },
    { key: 'workspace', heading: t('calendar.sidebar.workspaceCalendars'), items: workspace.value, add: props.canCreateShared ? () => emit('createCalendar', true) : null, addLabel: t('calendar.sidebar.addWorkspaceCalendar') },
    { key: 'others', heading: t('calendar.sidebar.otherCalendars'), items: others.value, add: null, addLabel: '' },
].filter((group) => group.items.length > 0 || group.add !== null));
const collapsed = ref<Record<string, boolean>>({});

const isVisible = (calendar: CalendarInfo): boolean => props.overrides[calendar.id] ?? calendar.visible;

function toggle(calendar: CalendarInfo): void {
    emit('toggle', calendar, !isVisible(calendar));
}

// People search: follow a teammate to see the calendars they share.
const query = ref('');
const matches = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();

    if (term === '') {
        return [];
    }

    return props.members.filter((member) => !props.followed.includes(member.id) && member.name.toLocaleLowerCase().includes(term)).slice(0, 8);
});

function follow(member: Person): void {
    query.value = '';
    router.post(route('calendar.people.store'), { member_id: member.id }, { preserveScroll: true, preserveState: true });
}

function unfollow(member: Person): void {
    router.delete(route('calendar.people.destroy', { membership: member.id }), { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <nav class="flex h-full min-h-0 flex-col gap-5 overflow-y-auto px-3 pt-1 pb-4" :aria-label="t('calendar.sidebar.label')">
        <div v-if="canCreate">
            <Dropdown align="start" width="w-52">
                <template #trigger>
                    <button type="button" class="glass inline-flex h-12 items-center gap-3 rounded-card px-4 font-medium text-ink shadow-glass transition hover:bg-control-hover focus-visible:ring-2 focus-visible:ring-ring">
                        <Plus class="size-5 text-brand-text" aria-hidden="true" />
                        {{ t('calendar.create') }}
                        <ChevronDown class="size-4 text-ink-subtle" aria-hidden="true" />
                    </button>
                </template>
                <DropdownItem @select="emit('createEvent')">{{ t('calendar.createEvent') }}</DropdownItem>
                <DropdownItem @select="emit('createCalendar', false)">{{ t('calendar.sidebar.addCalendar') }}</DropdownItem>
                <DropdownItem v-if="canCreateShared" @select="emit('createCalendar', true)">{{ t('calendar.sidebar.addWorkspaceCalendar') }}</DropdownItem>
            </Dropdown>
        </div>

        <MiniMonth :month="date" :today="today" :week-starts-on="weekStartsOn" :selected="range" @pick="emit('pick', $event)" />

        <div v-if="members.length" class="relative">
            <label class="relative block">
                <span class="sr-only">{{ t('calendar.people.search') }}</span>
                <UserRoundSearch class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
                <input v-model="query" type="search" class="control pl-9 text-sm" :placeholder="t('calendar.people.search')" autocomplete="off" @keydown.escape="query = ''" />
            </label>
            <ul v-if="query.trim() !== ''" class="glass-solid absolute inset-x-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-control p-1 shadow-float" role="listbox" :aria-label="t('calendar.people.search')">
                <li v-for="member in matches" :key="member.id">
                    <button type="button" class="flex w-full items-center gap-2 rounded-control px-2.5 py-2 text-left text-sm text-ink hover:bg-hover focus-visible:bg-hover focus-visible:outline-none" @click="follow(member)">
                        <Plus class="size-4 text-ink-subtle" aria-hidden="true" />
                        <span class="truncate">{{ member.name }}</span>
                    </button>
                </li>
                <li v-if="matches.length === 0" class="px-2.5 py-2 text-sm text-ink-subtle">{{ t('calendar.people.none') }}</li>
            </ul>
            <p class="mt-1 px-1 text-xs text-ink-subtle">{{ t('calendar.people.hint') }}</p>
        </div>

        <section v-for="group in groups" :key="group.key">
            <div class="mb-1 flex items-center justify-between gap-2 px-1">
                <button type="button" class="flex min-w-0 items-center gap-1 rounded-control text-sm font-medium text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-expanded="!collapsed[group.key]" @click="collapsed = { ...collapsed, [group.key]: !collapsed[group.key] }">
                    <span class="truncate">{{ group.heading }}</span>
                    <ChevronDown :class="['size-4 text-ink-subtle transition-transform', collapsed[group.key] ? '-rotate-90' : '']" aria-hidden="true" />
                </button>
                <button v-if="group.add" type="button" class="rounded-full p-1 text-ink-subtle hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="group.addLabel" :title="group.addLabel" @click="group.add">
                    <CalendarPlus class="size-4" aria-hidden="true" />
                </button>
            </div>

            <ul v-show="!collapsed[group.key]" class="space-y-0.5">
                <li v-for="calendar in group.items" :key="calendar.id" class="group flex items-center gap-1 rounded-control pr-1 hover:bg-hover" :style="colorVar(calendar.color)">
                    <button
                        type="button"
                        role="checkbox"
                        :aria-checked="isVisible(calendar)"
                        class="flex min-w-0 flex-1 items-center gap-2.5 rounded-control px-1.5 py-1.5 text-left text-sm text-ink focus-visible:ring-2 focus-visible:ring-ring"
                        @click="toggle(calendar)"
                    >
                        <span :class="['flex size-[1.125rem] shrink-0 items-center justify-center rounded-[0.3rem] border-2 border-(--ev)', isVisible(calendar) ? 'bg-(--ev) text-(--event-ink)' : '']" aria-hidden="true">
                            <Check v-if="isVisible(calendar)" class="size-3.5" :stroke-width="3" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ calendar.name }}</span>
                            <span v-if="calendar.busyOnly" class="block truncate text-xs text-ink-subtle">{{ t('calendar.sidebar.busyOnly') }}</span>
                        </span>
                    </button>
                    <Dropdown v-if="calendar.editable" align="end" width="w-48">
                        <template #trigger>
                            <button type="button" class="rounded-full p-1 text-ink-subtle opacity-100 transition hover:text-ink focus-visible:ring-2 focus-visible:ring-ring sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100" :aria-label="t('calendar.sidebar.options', { name: calendar.name })">
                                <MoreVertical class="size-4" aria-hidden="true" />
                            </button>
                        </template>
                        <DropdownItem @select="emit('editCalendar', calendar)">{{ t('calendar.sidebar.edit') }}</DropdownItem>
                    </Dropdown>
                </li>
            </ul>
        </section>

        <section v-if="followedPeople.length">
            <h2 class="mb-1 px-1 text-sm font-medium text-ink">{{ t('calendar.people.following') }}</h2>
            <ul class="space-y-0.5">
                <li v-for="person in followedPeople" :key="person.id" class="flex items-center gap-2 rounded-control px-1.5 py-1 text-sm text-ink hover:bg-hover">
                    <span class="min-w-0 flex-1 truncate">{{ person.name }}</span>
                    <button type="button" class="rounded-full p-1 text-ink-subtle hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('calendar.people.remove', { name: person.name })" @click="unfollow(person)">
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <p v-if="followedPeople.some((person) => !calendars.some((calendar) => calendar.owner?.id === person.id))" class="mt-1 px-1 text-xs text-ink-subtle">{{ t('calendar.people.nothingShared') }}</p>
        </section>
    </nav>
</template>

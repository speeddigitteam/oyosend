<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SocialBadge from '../../Components/SocialBadge.vue';
import { dayKey, monthGrid, monthParts, shiftMonth } from '../../Components/socialFormat';
import type { AccountOption, NamedRef, PostSummary } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    month: string;
    from: string;
    to: string;
    timezone: string;
    posts: PostSummary[];
    filters: { campaign_id: string | null; account_id: string | null };
    campaigns: NamedRef[];
    accounts: AccountOption[];
    canCreate: boolean;
    canSchedule: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

const campaign = ref(props.filters.campaign_id ?? '');
const account = ref(props.filters.account_id ?? '');

const filtered = computed(() => campaign.value !== '' || account.value !== '');
const campaignOptions = computed(() => [{ value: '', label: t('social.common.all') }, ...props.campaigns.map((item) => ({ value: item.id, label: item.name }))]);
const accountOptions = computed(() => [{ value: '', label: t('social.common.all') }, ...props.accounts.map((item) => ({ value: item.id, label: `${item.name} (${item.platformLabel})` }))]);

const weekdays = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;
const title = computed(() => {
    const { year, month } = monthParts(props.month);

    return t('social.calendar.monthTitle', {
        month: t(`social.calendar.months.${month}`),
        year: format.number(year, { useGrouping: false }),
    });
});

const weeks = computed(() => monthGrid(props.month));
const byDay = computed(() => {
    const map = new Map<string, PostSummary[]>();

    for (const post of props.posts) {
        if (!post.at) {
            continue;
        }

        const key = dayKey(post.at, props.timezone);
        map.set(key, [...(map.get(key) ?? []), post]);
    }

    return map;
});
const agenda = computed(() => [...byDay.value.entries()].sort(([a], [b]) => a.localeCompare(b)).filter(([key]) => key >= props.from && key <= props.to));

function visit(month: string): void {
    router.get(route('social.calendar.index'), {
        month,
        campaign_id: campaign.value || undefined,
        account_id: account.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([campaign, account], () => visit(props.month));

function clear(): void {
    campaign.value = '';
    account.value = '';
}

function label(post: PostSummary): string {
    return post.title ?? (post.excerpt !== '' ? post.excerpt : t('social.common.untitled'));
}

function time(post: PostSummary): string {
    return post.at ? format.date(post.at, 'time') : '';
}

function dayLabel(key: string): string {
    return format.date(key, 'date');
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.calendar.title')" :description="t('social.calendar.description', { timezone })">
        <template v-if="canCreate" #actions>
            <Button variant="primary" :href="route('social.posts.create')">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('social.posts.add') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <div class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3">
            <div class="flex items-center gap-1" role="group" :aria-label="t('social.calendar.navigate')">
                <Button size="sm" icon @click="visit(shiftMonth(month, -1))">
                    <template #icon><ChevronLeft class="size-4" aria-hidden="true" /></template>
                    {{ t('social.calendar.previous') }}
                </Button>
                <Button size="sm" icon @click="visit(shiftMonth(month, 1))">
                    <template #icon><ChevronRight class="size-4" aria-hidden="true" /></template>
                    {{ t('social.calendar.next') }}
                </Button>
            </div>
            <h2 class="mr-auto min-w-32 px-2 text-base" data-testid="calendar-title">{{ title }}</h2>
            <Select v-model="campaign" :options="campaignOptions" :label="t('social.fields.campaign')" class="w-full sm:w-44" />
            <Select v-model="account" :options="accountOptions" :label="t('social.fields.account')" class="w-full sm:w-52" />
            <Button v-if="filtered" size="sm" variant="ghost" @click="clear">{{ t('social.common.clearFilters') }}</Button>
        </div>

        <!-- Wide screens: the month as a grid. -->
        <div class="hidden md:block" data-testid="calendar-grid">
            <div class="grid grid-cols-7 border-b border-line">
                <p v-for="day in weekdays" :key="day" class="eyebrow px-2 py-2">{{ t(`social.calendar.weekdays.${day}`) }}</p>
            </div>
            <div v-for="(week, index) in weeks" :key="index" class="grid grid-cols-7 border-b border-line last:border-b-0">
                <div
                    v-for="cell in week"
                    :key="cell.date"
                    :class="['min-h-28 min-w-0 border-r border-line p-1.5 last:border-r-0', cell.inMonth ? '' : 'bg-hover']"
                >
                    <p :class="['tabular mb-1 px-1 text-xs', cell.inMonth ? 'text-ink-muted' : 'text-ink-subtle']">{{ format.number(cell.day) }}</p>
                    <ul class="grid gap-1">
                        <li v-for="post in byDay.get(cell.date) ?? []" :key="post.id">
                            <Link
                                :href="route('social.posts.show', { post: post.id })"
                                class="block rounded-control border border-line bg-control px-1.5 py-1 text-xs text-ink hover:bg-control-hover focus-visible:outline-2 focus-visible:outline-brand"
                            >
                                <span class="tabular text-ink-subtle">{{ time(post) }}</span>
                                <span class="block truncate">{{ label(post) }}</span>
                                <span class="mt-0.5 block text-ink-subtle">{{ t(`social.status.post.${post.status}`) }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Narrow screens: the same posts as a day by day list. -->
        <div class="md:hidden" data-testid="calendar-agenda">
            <ul v-if="agenda.length > 0" class="divide-y divide-line">
                <li v-for="[key, items] in agenda" :key="key" class="px-5 py-3">
                    <h3 class="eyebrow mb-2">{{ dayLabel(key) }}</h3>
                    <ul class="grid gap-2">
                        <li v-for="post in items" :key="post.id">
                            <Link :href="route('social.posts.show', { post: post.id })" class="flex flex-wrap items-center gap-2 text-ink hover:text-brand-text">
                                <span class="tabular text-xs text-ink-subtle">{{ time(post) }}</span>
                                <span class="min-w-0 break-words">{{ label(post) }}</span>
                                <SocialBadge kind="post" :value="post.status" />
                            </Link>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <EmptyState
            v-if="posts.length === 0"
            :title="filtered ? t('social.common.noMatches') : t('social.calendar.empty')"
            :description="filtered ? t('social.common.noMatchesBody') : t('social.calendar.emptyBody')"
            compact
        >
            <template #icon><CalendarDays /></template>
            <Button v-if="canCreate && !filtered" size="sm" variant="primary" :href="route('social.posts.create')">{{ t('social.posts.add') }}</Button>
        </EmptyState>

    </Card>
</template>

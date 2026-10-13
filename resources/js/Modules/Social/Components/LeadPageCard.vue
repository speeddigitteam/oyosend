<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Download, Link2, Power, RefreshCw, Settings2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { LeadAdsAvailability, LeadFormRow, LeadPageRow } from './leadAds';

const props = defineProps<{ page: LeadPageRow; availability: LeadAdsAvailability; canManage: boolean }>();
const emit = defineEmits<{ map: [form: LeadFormRow] }>();

const { t } = useI18n();
const format = useFormat();

const busy = ref<string | null>(null);
const days = ref(String(props.availability.backfillDays));

const STATE_TONES: Record<LeadPageRow['state'], BadgeTone> = { off: 'neutral', subscribed: 'ok', failed: 'warn' };

const on = computed(() => props.page.state === 'subscribed');
const usable = computed(() => props.page.account.status === 'connected');
const operatorReady = computed(() => props.availability.problem === null);
const enabledForms = computed(() => props.page.forms.filter((form) => form.isEnabled).length);
const maxCount = computed(() => Math.max(1, ...props.page.history.map((day) => day.received)));
const dayOptions = computed(() => {
    const max = props.availability.backfillMaxDays;
    const choices = [1, 3, 7, max].filter((value, index, all) => value <= max && all.indexOf(value) === index);

    return choices.map((value) => ({ value: String(value), label: t('leadAds.page.days', { count: format.number(value) }) }));
});

function post(name: string, url: string, data: Record<string, string | number | boolean> = {}): void {
    busy.value = name;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

function toggleForm(form: LeadFormRow): void {
    busy.value = `form-${form.id}`;
    router.post(route('social.leadads.forms.switch', { form: form.id }), { enabled: !form.isEnabled }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

function when(value: string | null): string {
    return value ? format.date(value, 'datetime') : t('leadAds.common.never');
}
</script>

<template>
    <Card class="min-w-0" :data-testid="`lead-page-${page.account.id}`">
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <img
                    v-if="page.account.avatarUrl && page.account.avatarUrl.startsWith('https://')"
                    :src="page.account.avatarUrl"
                    alt=""
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    class="size-9 shrink-0 rounded-full bg-hover object-cover"
                />
                <div class="min-w-0">
                    <h2 class="truncate text-[0.9375rem] font-semibold tracking-tight text-ink">{{ page.account.name }}</h2>
                    <p class="text-[0.8125rem] text-ink-subtle">{{ t('leadAds.page.pageId', { id: page.pageId }) }}</p>
                </div>
            </div>
        </template>
        <template #actions>
            <Badge :tone="STATE_TONES[page.state]" dot data-testid="page-state">{{ t(`leadAds.page.state.${page.state}`) }}</Badge>
        </template>

        <div class="grid gap-4">
            <!-- Connection state: always says what is wrong and what to do. -->
            <p v-if="!usable" class="rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="page-reconnect">
                {{ t('leadAds.page.needsReauth') }}
                <Link :href="route('social.accounts.index')" class="font-medium underline">{{ t('leadAds.page.goAccounts') }}</Link>
            </p>
            <p v-else-if="page.needsReconnect && operatorReady" class="rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="page-needs-scopes">
                {{ t('leadAds.page.needsScopes', { scopes: availability.requiredScopes.join(', ') }) }}
                <Link :href="route('social.accounts.index')" class="font-medium underline">{{ t('leadAds.page.goAccounts') }}</Link>
            </p>
            <p v-if="page.missingScopes.length > 0" class="rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="page-missing-scopes">
                {{ t('leadAds.page.missingScopes', { scopes: page.missingScopes.join(', ') }) }}
            </p>
            <p v-if="page.lastErrorCode && page.state !== 'off'" class="rounded-card bg-danger-soft px-4 py-3 text-[0.8125rem] text-danger" role="status" data-testid="page-error">
                {{ page.lastError }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <template v-if="canManage">
                    <Button
                        v-if="!on"
                        size="sm"
                        variant="primary"
                        :loading="busy === 'enable'"
                        :disabled="!usable || !operatorReady"
                        data-testid="enable"
                        @click="post('enable', route('social.leadads.enable', { account: page.account.id }))"
                    >
                        <template #icon><Power class="size-3.5" aria-hidden="true" /></template>
                        {{ t('leadAds.page.enable') }}
                    </Button>
                    <template v-else>
                        <Button size="sm" :loading="busy === 'forms'" @click="post('forms', route('social.leadads.forms.refresh', { account: page.account.id }))">
                            <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                            {{ t('leadAds.page.refreshForms') }}
                        </Button>
                        <Button size="sm" variant="ghost" :loading="busy === 'disable'" data-testid="disable" @click="post('disable', route('social.leadads.disable', { account: page.account.id }))">
                            {{ t('leadAds.page.disable') }}
                        </Button>
                    </template>
                </template>
                <p v-if="on" class="text-xs text-ink-subtle">
                    {{ page.formsSyncedAt ? t('leadAds.page.formsSynced', { when: when(page.formsSyncedAt) }) : t('leadAds.page.formsNever') }}
                </p>
            </div>
            <p v-if="page.formsSyncError" class="text-[0.8125rem] text-warn" role="status">{{ page.formsSyncError }}</p>

            <!-- Status history -->
            <section :aria-label="t('leadAds.history.title')">
                <dl class="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-4" data-testid="page-counts">
                    <div>
                        <dt class="eyebrow">{{ t('leadAds.stats.received') }}</dt>
                        <dd class="figure mt-1 text-xl text-ink">{{ format.number(page.counts.received) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('leadAds.stats.processed') }}</dt>
                        <dd class="figure mt-1 text-xl text-ink">{{ format.number(page.counts.processed) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('leadAds.stats.failed') }}</dt>
                        <dd :class="['figure mt-1 text-xl', page.counts.failed > 0 ? 'text-danger' : 'text-ink']">{{ format.number(page.counts.failed) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('leadAds.stats.waiting') }}</dt>
                        <dd :class="['figure mt-1 text-xl', page.counts.waiting > 0 ? 'text-warn' : 'text-ink']">{{ format.number(page.counts.waiting) }}</dd>
                    </div>
                </dl>

                <details v-if="page.counts.received > 0" class="mt-3">
                    <summary class="cursor-pointer text-[0.8125rem] font-medium text-ink-muted hover:text-ink">{{ t('leadAds.history.title') }}</summary>
                    <table class="mt-2 w-full text-[0.8125rem]">
                        <caption class="sr-only">{{ t('leadAds.history.caption') }}</caption>
                        <thead>
                            <tr class="text-left text-xs text-ink-subtle">
                                <th scope="col" class="py-1 pr-2 font-medium">{{ t('leadAds.history.day') }}</th>
                                <th scope="col" class="py-1 pr-2 font-medium">{{ t('leadAds.stats.received') }}</th>
                                <th scope="col" class="py-1 pr-2 font-medium">{{ t('leadAds.stats.processed') }}</th>
                                <th scope="col" class="py-1 font-medium">{{ t('leadAds.stats.failed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in page.history" :key="day.date" class="border-t border-line">
                                <td class="tabular py-1 pr-2 text-ink-muted">{{ format.date(day.date, 'date') }}</td>
                                <td class="py-1 pr-2">
                                    <span class="tabular text-ink">{{ format.number(day.received) }}</span>
                                    <span class="mt-0.5 block h-1 rounded-full bg-brand" :style="{ width: `${Math.round((day.received / maxCount) * 100)}%` }" aria-hidden="true" />
                                </td>
                                <td class="tabular py-1 pr-2 text-ink-muted">{{ format.number(day.processed) }}</td>
                                <td :class="['tabular py-1', day.failed > 0 ? 'text-danger' : 'text-ink-muted']">{{ format.number(day.failed) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </section>

            <!-- Forms -->
            <section v-if="on" :aria-label="t('leadAds.forms.title')">
                <h3 class="text-sm font-semibold text-ink">{{ t('leadAds.forms.title') }}</h3>
                <ul v-if="page.forms.length > 0" class="mt-2 divide-y divide-line rounded-card border border-line" data-testid="forms">
                    <li v-for="form in page.forms" :key="form.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :data-testid="`form-${form.id}`">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ form.name }}</p>
                            <p class="text-xs text-ink-subtle">
                                <template v-if="form.status">{{ form.status }} · </template>
                                {{ form.lastLeadAt ? t('leadAds.forms.lastLead', { when: when(form.lastLeadAt) }) : t('leadAds.forms.noLeads') }}
                                <template v-if="form.counts.received > 0"> · {{ t('leadAds.forms.leadsCount', { count: format.number(form.counts.received) }) }}</template>
                            </p>
                            <p v-if="form.isEnabled && form.warnings.includes('no_contact_field')" class="mt-1 text-xs text-warn">{{ t('leadAds.mapping.warnings.no_contact_field') }}</p>
                        </div>
                        <Button size="sm" variant="ghost" :disabled="!canManage" @click="emit('map', form)">
                            <template #icon><Settings2 class="size-3.5" aria-hidden="true" /></template>
                            {{ t('leadAds.forms.map') }}
                        </Button>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.isEnabled"
                            :aria-label="t('leadAds.forms.receive', { name: form.name })"
                            :disabled="!canManage || busy === `form-${form.id}`"
                            :data-testid="`switch-${form.id}`"
                            :class="[
                                'relative h-6 w-11 shrink-0 rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-55',
                                form.isEnabled ? 'border-brand bg-brand' : 'border-line-strong bg-control-sunken',
                            ]"
                            @click="toggleForm(form)"
                        >
                            <span
                                :class="[
                                    'absolute top-0.5 left-0.5 size-4.5 rounded-full shadow transition-transform',
                                    form.isEnabled ? 'translate-x-5 bg-brand-ink' : 'bg-ink-subtle',
                                ]"
                                aria-hidden="true"
                            />
                        </button>
                    </li>
                </ul>
                <EmptyState v-else :title="t('leadAds.forms.empty')" :description="t('leadAds.forms.emptyBody')" compact>
                    <template #icon><Link2 /></template>
                </EmptyState>

                <div v-if="canManage && enabledForms > 0" class="mt-3 flex flex-wrap items-end gap-2" data-testid="backfill">
                    <Select v-model="days" :options="dayOptions" :label="t('leadAds.page.lookBack')" class="w-40" />
                    <Button
                        size="sm"
                        :loading="busy === 'backfill'"
                        @click="post('backfill', route('social.leadads.backfill', { account: page.account.id }), { days: Number(days) })"
                    >
                        <template #icon><Download class="size-3.5" aria-hidden="true" /></template>
                        {{ t('leadAds.page.backfill') }}
                    </Button>
                    <p class="text-xs text-ink-subtle">
                        {{ page.lastBackfillAt ? t('leadAds.page.lastBackfill', { when: when(page.lastBackfillAt) }) : t('leadAds.page.backfillHint') }}
                    </p>
                </div>
            </section>
        </div>
    </Card>
</template>

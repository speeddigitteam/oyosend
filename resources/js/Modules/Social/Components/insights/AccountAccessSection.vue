<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw, ShieldCheck, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import SocialFormDialog from '../SocialFormDialog.vue';
import type { AccountRow } from '../types';
import type { AccessAccount, AccessEntry, AccessPanelProps } from './types';

/**
 * Per connected account: the Meta tasks and permissions its token holds, what each Oyosend
 * feature needs, and (for people who manage accounts) the allow-list of who may use the account.
 */
const props = defineProps<{ accounts: AccountRow[]; panel: AccessPanelProps }>();

const { t } = useI18n();
const format = useFormat();

const checking = ref<string | null>(null);
const editing = ref<AccountRow | null>(null);
const open = ref(false);
const saving = ref(false);
const entries = ref<AccessEntry[]>([]);
const adding = ref('');

const shown = computed(() => props.accounts.filter((account) => (account.capabilities as Record<string, unknown>).insights === true));

function info(account: AccountRow): AccessAccount | undefined {
    return props.panel.accounts[account.id];
}

function featureState(account: AccountRow, task: string): 'has' | 'missing' | 'unknown' {
    const tasks = info(account)?.tasks;

    if (tasks === null || tasks === undefined) {
        return 'unknown';
    }

    return tasks.includes(task) ? 'has' : 'missing';
}

function check(account: AccountRow): void {
    checking.value = account.id;
    router.post(route('social.accounts.access.check', { account: account.id }), {}, {
        preserveScroll: true,
        onFinish: () => {
            checking.value = null;
        },
    });
}

function edit(account: AccountRow): void {
    editing.value = account;
    entries.value = (info(account)?.entries ?? []).map((entry) => ({ ...entry }));
    adding.value = '';
    open.value = true;
}

const subjects = computed(() => [
    ...props.panel.members.map((member) => ({ type: 'membership' as const, id: member.id, name: member.name, label: t('socialInsights.access.member', { name: member.name }) })),
    ...props.panel.roles.map((role) => ({ type: 'role' as const, id: role.id, name: role.name, label: t('socialInsights.access.role', { name: role.name }) })),
]);

const choices = computed(() => subjects.value
    .filter((subject) => !entries.value.some((entry) => entry.subjectType === subject.type && entry.subjectId === subject.id))
    .map((subject) => ({ value: `${subject.type}:${subject.id}`, label: subject.label })));

function subjectName(entry: AccessEntry): string {
    return subjects.value.find((subject) => subject.type === entry.subjectType && subject.id === entry.subjectId)?.label ?? t('socialInsights.access.unknownSubject');
}

function add(value: string | number | null | undefined): void {
    const [type, id] = String(value ?? '').split(':');

    if ((type === 'membership' || type === 'role') && id) {
        entries.value = [...entries.value, { subjectType: type, subjectId: id, canPublish: true, canApprove: false, canAnalytics: true }];
    }

    adding.value = '';
}

function remove(index: number): void {
    entries.value = entries.value.filter((_, i) => i !== index);
}

function save(): void {
    if (!editing.value) {
        return;
    }

    saving.value = true;
    router.put(route('social.accounts.access.update', { account: editing.value.id }), {
        entries: entries.value.map((entry) => ({
            subject_type: entry.subjectType,
            subject_id: entry.subjectId,
            can_publish: entry.canPublish,
            can_approve: entry.canApprove,
            can_analytics: entry.canAnalytics,
        })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}
</script>

<template>
    <Card v-if="shown.length > 0" class="mt-6" :title="t('socialInsights.access.title')" :description="t('socialInsights.access.hint')" data-testid="access-panel">
        <ul class="grid gap-5">
            <li v-for="account in shown" :key="account.id" class="min-w-0 border-b border-line pb-5 last:border-b-0 last:pb-0" :data-testid="`access-${account.id}`">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-ink">{{ account.name }}</p>
                        <p class="text-xs text-ink-subtle">{{ account.platformLabel }}</p>
                    </div>
                    <div v-if="panel.canManage" class="flex flex-wrap gap-1.5">
                        <Button size="sm" variant="ghost" :loading="checking === account.id" :disabled="account.status !== 'connected'" @click="check(account)">
                            <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                            {{ t('socialInsights.access.check') }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="edit(account)">
                            <template #icon><Users class="size-3.5" aria-hidden="true" /></template>
                            {{ t('socialInsights.access.who') }}
                        </Button>
                    </div>
                </div>

                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                    <div>
                        <p class="eyebrow">{{ t('socialInsights.access.tasks') }}</p>
                        <p v-if="info(account)?.tasks === null || info(account)?.tasks === undefined" class="mt-1 text-[0.8125rem] text-ink-muted" data-testid="tasks-unknown">
                            {{ info(account)?.tasksStatus === 'permission_missing' ? t('socialInsights.access.tasksPermission') : t('socialInsights.access.tasksUnknown') }}
                        </p>
                        <p v-else-if="info(account)!.tasks!.length === 0" class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.access.tasksNone') }}</p>
                        <ul v-else class="mt-1 flex flex-wrap gap-1.5" data-testid="tasks">
                            <li v-for="task in info(account)!.tasks!" :key="task"><Badge tone="brand">{{ t(`socialInsights.tasks.${task}`) }}</Badge></li>
                        </ul>
                        <p v-if="info(account)?.tasksCheckedAt" class="mt-1 text-xs text-ink-subtle">{{ t('socialInsights.access.checkedAt', { when: format.date(info(account)!.tasksCheckedAt, 'datetime') }) }}</p>

                        <p class="eyebrow mt-3">{{ t('socialInsights.access.permissions') }}</p>
                        <p v-if="!info(account)?.scopes" class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.access.scopesUnknown') }}</p>
                        <ul v-else class="mt-1 flex flex-wrap gap-1.5">
                            <li v-for="scope in info(account)!.scopes!" :key="scope"><code class="rounded-chip bg-hover px-1.5 py-0.5 font-mono text-xs text-ink-muted">{{ scope }}</code></li>
                        </ul>
                    </div>

                    <div class="min-w-0 overflow-x-auto">
                        <table class="w-full text-left text-[0.8125rem]">
                            <caption class="sr-only">{{ t('socialInsights.access.featuresCaption', { name: account.name }) }}</caption>
                            <thead>
                                <tr class="border-b border-line">
                                    <th scope="col" class="eyebrow py-1.5 pe-3 font-normal">{{ t('socialInsights.access.feature') }}</th>
                                    <th scope="col" class="eyebrow py-1.5 pe-3 font-normal">{{ t('socialInsights.access.needs') }}</th>
                                    <th scope="col" class="eyebrow py-1.5 font-normal">{{ t('socialInsights.access.state') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in panel.features" :key="item.feature" class="border-b border-line last:border-b-0">
                                    <th scope="row" class="py-1.5 pe-3 font-normal text-ink">
                                        {{ t(`socialInsights.features.${item.feature}`) }}
                                        <span v-if="item.permissions.length > 0" class="block text-xs text-ink-subtle">{{ item.permissions.join(', ') }}</span>
                                    </th>
                                    <td class="py-1.5 pe-3 text-ink-muted">{{ t(`socialInsights.tasks.${item.task}`) }}</td>
                                    <td class="py-1.5">
                                        <Badge :tone="featureState(account, item.task) === 'has' ? 'ok' : featureState(account, item.task) === 'missing' ? 'warn' : 'neutral'" dot>
                                            {{ t(`socialInsights.access.states.${featureState(account, item.task)}`) }}
                                        </Badge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="mt-3 flex flex-wrap items-center gap-1.5 text-[0.8125rem] text-ink-muted">
                    <ShieldCheck class="size-4 text-ink-subtle" aria-hidden="true" />
                    <template v-if="info(account)?.restricted">{{ t('socialInsights.access.restricted', { count: format.number(info(account)!.entryCount) }) }}</template>
                    <template v-else>{{ t('socialInsights.access.open') }}</template>
                </p>
            </li>
        </ul>
    </Card>

    <SocialFormDialog
        v-model:open="open"
        size="lg"
        :title="t('socialInsights.access.dialogTitle', { name: editing?.name ?? '' })"
        :description="t('socialInsights.access.dialogBody')"
        :submit-label="t('social.common.save')"
        :processing="saving"
        @submit="save"
    >
        <p v-if="entries.length === 0" class="rounded-card bg-hover px-3 py-2 text-[0.8125rem] text-ink-muted">{{ t('socialInsights.access.dialogOpen') }}</p>
        <ul v-else class="grid gap-3" data-testid="allow-list">
            <li v-for="(entry, index) in entries" :key="`${entry.subjectType}:${entry.subjectId}`" class="rounded-card border border-line px-3 py-2">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                    <p class="min-w-0 text-sm font-medium wrap-break-word text-ink">{{ subjectName(entry) }}</p>
                    <Button size="sm" variant="ghost" @click="remove(index)">{{ t('socialInsights.access.remove') }}</Button>
                </div>
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                    <Checkbox v-model="entry.canPublish" :label="t('socialInsights.access.canPublish')" />
                    <Checkbox v-model="entry.canApprove" :label="t('socialInsights.access.canApprove')" />
                    <Checkbox v-model="entry.canAnalytics" :label="t('socialInsights.access.canAnalytics')" />
                </div>
            </li>
        </ul>
        <Select
            v-if="choices.length > 0"
            v-model="adding"
            :label="t('socialInsights.access.add')"
            :options="choices"
            :placeholder="t('socialInsights.access.addPlaceholder')"
            @update:model-value="add"
        />
        <p class="text-xs text-ink-subtle">{{ t('socialInsights.access.ownerNote') }}</p>
    </SocialFormDialog>
</template>

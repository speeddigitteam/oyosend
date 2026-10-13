<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { MessageCircle, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import RuleDialog from '../../Components/comments/RuleDialog.vue';
import type { CommentPage, CommentRuleData, Member, RuleActionType } from '../../Components/comments/commentTypes';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    rules: CommentRuleData[];
    pages: CommentPage[];
    paused: boolean;
    pausedAt: string | null;
    pageHourlyCap: number;
    options: {
        accounts: { id: string; name: string; platform: string }[];
        posts: { id: string; name: string }[];
        campaigns: { id: string; name: string }[];
        members: Member[];
        actions: RuleActionType[];
        variables: string[];
    };
    limits: { keywords: number; keyword: number; template: number; templates: number; tag: number; rules: number; previewText: number };
}>();

const { t } = useI18n();
const format = useFormat();

const dialog = ref(false);
const editing = ref<CommentRuleData | null>(null);
const removing = ref<CommentRuleData | null>(null);
const removeOpen = ref(false);
const cap = useForm({ page_hourly_cap: props.pageHourlyCap });

function create(): void {
    editing.value = null;
    dialog.value = true;
}

function edit(rule: CommentRuleData): void {
    editing.value = rule;
    dialog.value = true;
}

function toggle(rule: CommentRuleData): void {
    router.put(route('social.comments.rules.toggle', { rule: rule.id }), { enabled: !rule.enabled }, { preserveScroll: true });
}

function askRemove(rule: CommentRuleData): void {
    removing.value = rule;
    removeOpen.value = true;
}

function remove(): void {
    if (removing.value) {
        router.delete(route('social.comments.rules.destroy', { rule: removing.value.id }), { preserveScroll: true, onFinish: () => (removeOpen.value = false) });
    }
}

function setPaused(paused: boolean): void {
    router.put(route('social.comments.automation'), { paused }, { preserveScroll: true });
}

function setSource(page: CommentPage, on: boolean): void {
    if (on) {
        router.post(route('social.comments.sources.enable', { account: page.accountId }), {}, { preserveScroll: true });
    } else {
        router.delete(route('social.comments.sources.disable', { account: page.accountId }), { preserveScroll: true });
    }
}

function saveCap(): void {
    cap.put(route('social.comments.limits'), { preserveScroll: true });
}

function pageState(page: CommentPage): { tone: BadgeTone; key: string } {
    if (!page.supported) {
        return { tone: 'neutral', key: 'unsupported' };
    }

    if (!page.connected) {
        return { tone: 'warn', key: 'reconnect' };
    }

    if (!page.enabled) {
        return { tone: 'neutral', key: 'off' };
    }

    return page.webhookState === 'subscribed' ? { tone: 'ok', key: 'live' } : { tone: 'info', key: 'polling' };
}

function standingTone(rule: CommentRuleData): BadgeTone {
    return rule.standing ? 'warn' : rule.enabled ? 'ok' : 'neutral';
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('socialComments.rules.title')" :description="t('socialComments.rules.description')">
        <template #actions>
            <Button :href="route('social.comments.index')">
                <template #icon><MessageCircle class="size-4" aria-hidden="true" /></template>
                {{ t('socialComments.rules.backToInbox') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-4">
        <Card :title="t('socialComments.rules.killTitle')" :description="paused ? t('socialComments.rules.killPaused') : t('socialComments.rules.killRunning')" data-testid="kill-switch">
            <template #actions>
                <Button v-if="!paused" variant="danger" size="sm" @click="setPaused(true)"><Pause class="size-4" aria-hidden="true" />{{ t('socialComments.rules.pauseAll') }}</Button>
                <Button v-else variant="primary" size="sm" @click="setPaused(false)"><Play class="size-4" aria-hidden="true" />{{ t('socialComments.rules.resumeAll') }}</Button>
            </template>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="saveCap">
                <Input v-model.number="cap.page_hourly_cap" type="number" min="1" max="500" :label="t('socialComments.rules.pageCap')" :hint="t('socialComments.rules.pageCapHint')" :error="cap.errors.page_hourly_cap" class="w-full sm:w-64" />
                <Button type="submit" size="sm" :loading="cap.processing">{{ t('social.common.save') }}</Button>
            </form>
        </Card>

        <Card :title="t('socialComments.pages.title')" :description="t('socialComments.pages.description')" flush>
            <EmptyState v-if="pages.length === 0" compact :title="t('socialComments.pages.emptyTitle')" :description="t('socialComments.pages.emptyBody')">
                <template #icon><MessageCircle /></template>
                <Button size="sm" variant="primary" :href="route('social.accounts.index')">{{ t('socialComments.pages.connect') }}</Button>
            </EmptyState>
            <ul v-else>
                <li v-for="page in pages" :key="page.accountId" class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 last:border-b-0" :data-testid="`page-${page.accountId}`">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ page.name }} <span class="text-ink-subtle">({{ page.platformLabel }})</span></p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            <template v-if="page.enabled && page.lastEventAt">{{ t('socialComments.pages.lastEvent', { when: format.relative(page.lastEventAt) }) }}</template>
                            <template v-else-if="page.enabled && page.lastPolledAt">{{ t('socialComments.pages.lastPoll', { when: format.relative(page.lastPolledAt) }) }}</template>
                            <template v-else>{{ t(`socialComments.pages.hint.${pageState(page).key}`) }}</template>
                            <template v-if="page.errorCode"> · {{ t('socialComments.pages.error', { code: page.errorCode }) }}</template>
                        </p>
                    </div>
                    <Badge :tone="pageState(page).tone" dot>{{ t(`socialComments.pages.state.${pageState(page).key}`) }}</Badge>
                    <Button v-if="page.supported && page.connected && !page.enabled" size="sm" variant="primary" @click="setSource(page, true)">{{ t('socialComments.pages.turnOn') }}</Button>
                    <Button v-else-if="page.supported && page.enabled" size="sm" @click="setSource(page, false)">{{ t('socialComments.pages.turnOff') }}</Button>
                    <Button v-else-if="page.supported && !page.connected" size="sm" :href="route('social.accounts.index')">{{ t('socialComments.pages.reconnect') }}</Button>
                </li>
            </ul>
        </Card>

        <Card :title="t('socialComments.rules.listTitle')" flush>
            <template #actions>
                <Button variant="primary" size="sm" :disabled="rules.length >= limits.rules" @click="create"><Plus class="size-4" aria-hidden="true" />{{ t('socialComments.rules.add') }}</Button>
            </template>
            <EmptyState v-if="rules.length === 0" compact :title="t('socialComments.rules.emptyTitle')" :description="t('socialComments.rules.emptyBody')">
                <template #icon><MessageCircle /></template>
                <Button size="sm" variant="primary" @click="create">{{ t('socialComments.rules.add') }}</Button>
            </EmptyState>
            <ul v-else>
                <li v-for="rule in rules" :key="rule.id" class="grid gap-2 border-b border-line px-5 py-4 last:border-b-0" :data-testid="`rule-${rule.id}`">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ rule.name }}</p>
                        <Badge :tone="standingTone(rule)" dot>{{ rule.enabled ? t('socialComments.rules.on') : t('socialComments.rules.off') }}</Badge>
                        <Button size="sm" :variant="rule.enabled ? 'secondary' : 'primary'" @click="toggle(rule)">{{ rule.enabled ? t('socialComments.rules.switchOff') : t('socialComments.rules.switchOn') }}</Button>
                        <Button size="sm" variant="ghost" icon :aria-label="t('social.common.edit')" @click="edit(rule)"><Pencil class="size-4" aria-hidden="true" /></Button>
                        <Button size="sm" variant="ghost" icon :aria-label="t('social.common.delete')" @click="askRemove(rule)"><Trash2 class="size-4" aria-hidden="true" /></Button>
                    </div>
                    <p class="break-words text-[0.8125rem] text-ink-muted">
                        {{ t(rule.matchType === 'word' ? 'socialComments.rules.summaryWord' : 'socialComments.rules.summaryContains', { keywords: rule.keywords.join(', ') }) }}
                    </p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Badge v-for="action in rule.actions" :key="action.type" tone="brand">{{ t(`socialComments.rule.action.${action.type}`) }}</Badge>
                        <span class="text-xs text-ink-subtle">{{ t('socialComments.rules.matched', { count: format.number(rule.matchedCount) }) }}</span>
                        <span v-if="rule.actor" class="text-xs text-ink-subtle">· {{ t('socialComments.rules.runsAs', { name: rule.actor.name }) }}</span>
                    </div>
                    <p v-if="rule.standing" role="alert" class="rounded-control bg-warn-soft px-3 py-2 text-[0.8125rem] text-warn">
                        {{ t(`socialComments.rules.standing.${rule.standing}`) }}
                    </p>
                </li>
            </ul>
        </Card>
    </div>

    <RuleDialog v-model:open="dialog" :rule="editing" :options="options" :limits="limits" />
    <ConfirmDialog v-model:open="removeOpen" :title="t('socialComments.rules.deleteTitle')" :description="t('socialComments.rules.deleteBody')" :confirm-label="t('social.common.delete')" @confirm="remove" />
</template>

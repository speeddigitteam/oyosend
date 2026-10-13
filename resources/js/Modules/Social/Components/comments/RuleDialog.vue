<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import { type CommentRuleData, type Member, postJson, type RuleActionType, type RulePreview } from './commentTypes';

/** Create or edit one keyword rule, with a test box that shows what it would do (nothing is sent or saved). */
const props = defineProps<{
    rule: CommentRuleData | null;
    options: {
        accounts: { id: string; name: string; platform: string }[];
        posts: { id: string; name: string }[];
        campaigns: { id: string; name: string }[];
        members: Member[];
        actions: RuleActionType[];
        variables: string[];
    };
    limits: { keywords: number; keyword: number; template: number; templates: number; tag: number; previewText: number };
}>();

const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();

const form = useForm({
    name: '',
    match_mode: 'any' as 'any' | 'all',
    match_type: 'word' as 'word' | 'contains',
    keywords: [] as string[],
    account_id: '',
    post_id: '',
    campaign_id: '',
    actions: [] as { type: RuleActionType; message?: string; membership_id?: string; tag?: string }[],
    reply_templates: [''] as string[],
    hourly_cap: 20,
    cooldown_minutes: 60,
    quiet_start: '',
    quiet_end: '',
    priority: 100,
});

const keywordDraft = ref('');
const test = reactive({ text: '', author: '', result: null as RulePreview | null, failed: false, busy: false });

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const rule = props.rule;
    form.defaults({
        name: rule?.name ?? '',
        match_mode: rule?.matchMode ?? 'any',
        match_type: rule?.matchType ?? 'word',
        keywords: [...(rule?.keywords ?? [])],
        account_id: rule?.accountId ?? '',
        post_id: rule?.postId ?? '',
        campaign_id: rule?.campaignId ?? '',
        actions: (rule?.actions ?? [{ type: 'review' as RuleActionType }]).map((action) => ({ ...action })),
        reply_templates: rule && rule.replyTemplates.length > 0 ? [...rule.replyTemplates] : [''],
        hourly_cap: rule?.hourlyCap ?? 20,
        cooldown_minutes: rule?.cooldownMinutes ?? 60,
        quiet_start: rule?.quietStart ?? '',
        quiet_end: rule?.quietEnd ?? '',
        priority: rule?.priority ?? 100,
    }).reset();
    form.clearErrors();
    keywordDraft.value = '';
    test.text = '';
    test.result = null;
});

const matchModes = computed(() => [
    { value: 'any' as const, label: t('socialComments.rule.matchAny') },
    { value: 'all' as const, label: t('socialComments.rule.matchAll') },
]);
const matchTypes = computed(() => [
    { value: 'word' as const, label: t('socialComments.rule.typeWord') },
    { value: 'contains' as const, label: t('socialComments.rule.typeContains') },
]);
const accountOptions = computed(() => [{ value: '', label: t('socialComments.rule.anyPage') }, ...props.options.accounts.map((item) => ({ value: item.id, label: item.name }))]);
const postOptions = computed(() => [{ value: '', label: t('socialComments.rule.anyPost') }, ...props.options.posts.map((item) => ({ value: item.id, label: item.name }))]);
const campaignOptions = computed(() => [{ value: '', label: t('socialComments.rule.anyCampaign') }, ...props.options.campaigns.map((item) => ({ value: item.id, label: item.name }))]);
const memberOptions = computed(() => [{ value: '', label: t('socialComments.bulk.chooseMember') }, ...props.options.members.map((item) => ({ value: item.id, label: item.name }))]);
const variableHint = computed(() => props.options.variables.map((name) => `{${name}}`).join('  '));

function has(type: RuleActionType): boolean {
    return form.actions.some((action) => action.type === type);
}

function actionOf(type: RuleActionType) {
    return form.actions.find((action) => action.type === type);
}

function setAction(type: RuleActionType, on: boolean): void {
    if (on && !has(type)) {
        form.actions.push({ type });
    } else if (!on) {
        form.actions = form.actions.filter((action) => action.type !== type);
    }
}

function addKeyword(): void {
    const value = keywordDraft.value.trim();

    if (value !== '' && form.keywords.length < props.limits.keywords && !form.keywords.some((item) => item.toLowerCase() === value.toLowerCase())) {
        form.keywords.push(value);
    }

    keywordDraft.value = '';
}

function addTemplate(): void {
    if (form.reply_templates.length < props.limits.templates) {
        form.reply_templates.push('');
    }
}

function payload() {
    return {
        ...form.data(),
        account_id: form.account_id || null,
        post_id: form.post_id || null,
        campaign_id: form.campaign_id || null,
        quiet_start: form.quiet_start || null,
        quiet_end: form.quiet_end || null,
        reply_templates: has('reply') ? form.reply_templates.filter((item) => item.trim() !== '') : [],
    };
}

function save(): void {
    addKeyword();
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    form.transform(() => payload());

    if (props.rule) {
        form.put(route('social.comments.rules.update', { rule: props.rule.id }), options);
    } else {
        form.post(route('social.comments.rules.store'), options);
    }
}

async function runTest(): Promise<void> {
    test.busy = true;
    test.failed = false;
    const result = await postJson<RulePreview>(route('social.comments.rules.preview'), {
        ...payload(),
        text: test.text,
        author_name: test.author || null,
        page_name: props.options.accounts.find((item) => item.id === form.account_id)?.name ?? null,
    });
    test.result = result;
    test.failed = result === null;
    test.busy = false;
}
</script>

<template>
    <Modal v-model:open="open" size="lg" persistent :title="rule ? t('socialComments.rule.editTitle') : t('socialComments.rule.newTitle')" :description="t('socialComments.rule.description')">
        <form class="grid max-h-[70vh] gap-5 overflow-y-auto px-1 py-1" @submit.prevent="save">
            <Input v-model="form.name" :label="t('socialComments.rule.name')" maxlength="120" :error="form.errors.name" required />

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('socialComments.rule.whenTitle') }}</legend>
                <div>
                    <label class="block text-sm font-medium text-ink" for="rule-keyword">{{ t('socialComments.rule.keywords') }}</label>
                    <p class="mt-0.5 text-xs text-ink-subtle">{{ t('socialComments.rule.keywordsHint', { max: limits.keywords }) }}</p>
                    <ul v-if="form.keywords.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                        <li v-for="(keyword, index) in form.keywords" :key="keyword">
                            <span class="inline-flex items-center gap-1 rounded-full bg-hover px-2.5 py-1 text-[0.8125rem] text-ink">
                                {{ keyword }}
                                <button type="button" class="rounded-full text-ink-subtle hover:text-ink focus-visible:outline-2 focus-visible:outline-brand" :aria-label="t('socialComments.rule.removeKeyword', { keyword })" @click="form.keywords.splice(index, 1)">
                                    <X class="size-3" aria-hidden="true" />
                                </button>
                            </span>
                        </li>
                    </ul>
                    <div class="mt-2 flex gap-2">
                        <Input id="rule-keyword" v-model="keywordDraft" :maxlength="limits.keyword" class="min-w-0 flex-1" :placeholder="t('socialComments.rule.keywordPlaceholder')" @keydown.enter.prevent="addKeyword" />
                        <Button type="button" size="md" @click="addKeyword"><Plus class="size-4" aria-hidden="true" />{{ t('socialComments.actions.add') }}</Button>
                    </div>
                    <p v-if="form.errors.keywords" role="alert" class="mt-1 text-[0.8125rem] text-danger">{{ form.errors.keywords }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <SegmentedControl v-model="form.match_mode" :options="matchModes" :label="t('socialComments.rule.matchLabel')" />
                    <SegmentedControl v-model="form.match_type" :options="matchTypes" :label="t('socialComments.rule.typeLabel')" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <Select v-model="form.account_id" :options="accountOptions" :label="t('socialComments.rule.page')" />
                    <Select v-model="form.post_id" :options="postOptions" :label="t('socialComments.rule.post')" />
                    <Select v-model="form.campaign_id" :options="campaignOptions" :label="t('socialComments.rule.campaign')" />
                </div>
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('socialComments.rule.thenTitle') }}</legend>
                <p v-if="form.errors.actions" role="alert" class="text-[0.8125rem] text-danger">{{ form.errors.actions }}</p>
                <div v-for="type in options.actions" :key="type" class="grid gap-2 rounded-control border border-line px-3 py-2.5">
                    <Checkbox :model-value="has(type)" :label="t(`socialComments.rule.action.${type}`)" :description="t(`socialComments.rule.actionHint.${type}`)" @update:model-value="setAction(type, $event)" />
                    <template v-if="has(type) && type === 'reply'">
                        <p class="text-xs text-ink-subtle">{{ t('socialComments.rule.templatesHint', { variables: variableHint }) }}</p>
                        <div v-for="(template, index) in form.reply_templates" :key="index" class="flex items-start gap-2">
                            <Textarea v-model="form.reply_templates[index]" :rows="2" :maxlength="limits.template" :aria-label="t('socialComments.rule.templateN', { n: index + 1 })" class="min-w-0 flex-1" />
                            <Button v-if="form.reply_templates.length > 1" type="button" size="sm" variant="ghost" icon :aria-label="t('socialComments.rule.removeTemplate')" @click="form.reply_templates.splice(index, 1)"><X class="size-4" aria-hidden="true" /></Button>
                        </div>
                        <p v-if="form.errors.reply_templates" role="alert" class="text-[0.8125rem] text-danger">{{ form.errors.reply_templates }}</p>
                        <div><Button v-if="form.reply_templates.length < limits.templates" type="button" size="sm" @click="addTemplate"><Plus class="size-4" aria-hidden="true" />{{ t('socialComments.rule.addTemplate') }}</Button></div>
                    </template>
                    <Textarea v-else-if="has(type) && type === 'private_reply'" v-model="actionOf('private_reply')!.message" :rows="2" :maxlength="limits.template" :label="t('socialComments.rule.privateMessage')" :hint="variableHint" />
                    <Select v-else-if="has(type) && type === 'assign'" v-model="actionOf('assign')!.membership_id" :options="memberOptions" :label="t('socialComments.rule.assignee')" />
                    <Input v-else-if="has(type) && type === 'tag'" v-model="actionOf('tag')!.tag" :maxlength="limits.tag" :label="t('socialComments.rule.tagName')" />
                </div>
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('socialComments.rule.safetyTitle') }}</legend>
                <p class="text-xs text-ink-subtle">{{ t('socialComments.rule.safetyHint') }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <Input v-model.number="form.hourly_cap" type="number" min="1" max="200" :label="t('socialComments.rule.hourlyCap')" :error="form.errors.hourly_cap" />
                    <Input v-model.number="form.cooldown_minutes" type="number" min="0" max="10080" :label="t('socialComments.rule.cooldown')" :hint="t('socialComments.rule.cooldownHint')" :error="form.errors.cooldown_minutes" />
                    <Input v-model.number="form.priority" type="number" min="1" max="1000" :label="t('socialComments.rule.priority')" :hint="t('socialComments.rule.priorityHint')" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Input v-model="form.quiet_start" type="time" :label="t('socialComments.rule.quietStart')" :error="form.errors.quiet_start" optional />
                    <Input v-model="form.quiet_end" type="time" :label="t('socialComments.rule.quietEnd')" :hint="t('socialComments.rule.quietHint')" optional />
                </div>
            </fieldset>

            <fieldset class="grid gap-3 rounded-control bg-hover px-3 py-3" data-testid="rule-test-box">
                <legend class="eyebrow mb-1 px-1">{{ t('socialComments.rule.testTitle') }}</legend>
                <p class="text-xs text-ink-subtle">{{ t('socialComments.rule.testHint') }}</p>
                <Textarea v-model="test.text" :rows="2" :maxlength="limits.previewText" :label="t('socialComments.rule.testText')" />
                <Input v-model="test.author" maxlength="80" :label="t('socialComments.rule.testAuthor')" optional />
                <div><Button type="button" size="sm" :loading="test.busy" :disabled="test.text.trim() === ''" @click="runTest">{{ t('socialComments.rule.testRun') }}</Button></div>
                <div v-if="test.result" role="status" class="grid gap-1.5 text-sm" data-testid="rule-test-result">
                    <p v-if="!test.result.matched" class="text-ink-muted">{{ t('socialComments.rule.testNoMatch') }}</p>
                    <template v-else>
                        <p class="text-ok">{{ t('socialComments.rule.testMatch', { keywords: test.result.hits.join(', ') }) }}</p>
                        <p v-if="test.result.quiet" class="text-warn">{{ t('socialComments.rule.testQuiet') }}</p>
                        <ul class="grid gap-1">
                            <li v-for="(planned, index) in test.result.actions" :key="index" class="rounded-control bg-control px-2.5 py-1.5">
                                <Badge tone="brand">{{ t(`socialComments.rule.action.${planned.type}`) }}</Badge>
                                <span v-if="planned.detail" class="ms-2 break-words whitespace-pre-line text-ink">{{ planned.detail }}</span>
                                <span v-if="planned.note" class="ms-2 text-xs text-ink-subtle">{{ t(`socialComments.rule.note.${planned.note}`) }}</span>
                            </li>
                        </ul>
                    </template>
                </div>
                <p v-if="test.failed" role="alert" class="text-[0.8125rem] text-danger">{{ t('socialComments.rule.testFailed') }}</p>
            </fieldset>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="ghost" @click="open = false">{{ t('social.common.cancel') }}</Button>
                <Button type="submit" variant="primary" :loading="form.processing">{{ t('social.common.save') }}</Button>
            </div>
        </form>
    </Modal>
</template>

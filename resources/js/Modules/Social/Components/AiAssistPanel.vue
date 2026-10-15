<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy, Hash, Info, RefreshCw, Sparkles, Undo2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { MAX_HASHTAGS, mergeTags, requestSuggestion, type AiOperation, type AiSuggestion } from './aiAssist';

type Mode = 'caption' | 'hashtags' | 'improve';
type ImproveOp = 'rewrite' | 'shorten' | 'translate';

/**
 * Suggestions for the post being written: a caption, hashtags, or a rewrite, shorter or translated version.
 * Everything shown here is a suggestion. Nothing reaches the post until the person presses the accept button,
 * and accepting marks the draft as AI-assisted so approvers can see it. Nothing is saved or published here.
 */
const props = defineProps<{
    title: string;
    linkUrl: string;
    accountIds: string[];
}>();

const body = defineModel<string>('body', { required: true });
const extraTags = defineModel<string[]>('extraTags', { required: true });
const marked = defineModel<boolean>('marked', { required: true });

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const mode = ref<Mode>('caption');
const improveOp = ref<ImproveOp>('rewrite');
const notes = ref('');
const tone = ref('friendly');
const language = ref('same');
const count = ref('8');

const loading = ref(false);
const result = ref<AiSuggestion | null>(null);
const attempts = ref<Record<string, number>>({});
const picked = ref<string[]>([]);
const copied = ref(false);
const applied = ref<'text' | 'tags' | null>(null);
const previousBody = ref<string | null>(null);
let controller: AbortController | null = null;
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

const canAssist = computed(() => can('social.posts.ai_assist'));
const canSaveSet = computed(() => can('social.library.manage'));

const modes = computed(() => [
    { value: 'caption' as const, label: t('socialAi.modes.caption') },
    { value: 'hashtags' as const, label: t('socialAi.modes.hashtags') },
    { value: 'improve' as const, label: t('socialAi.modes.improve') },
]);
const toneOptions = computed(() => ['friendly', 'professional', 'playful', 'persuasive', 'formal'].map((value) => ({ value, label: t(`socialAi.tones.${value}`) })));
const languageOptions = computed(() => [
    { value: 'same', label: t('socialAi.languages.same') },
    { value: 'en', label: t('socialAi.languages.en') },
    { value: 'bn', label: t('socialAi.languages.bn') },
]);
const translateOptions = computed(() => languageOptions.value.filter((option) => option.value !== 'same'));
const improveOptions = computed(() => (['rewrite', 'shorten', 'translate'] as const).map((value) => ({ value, label: t(`socialAi.improve.${value}`) })));
const countOptions = computed(() => ['5', '8', '10', '15'].map((value) => ({ value, label: format.number(Number(value)) })));

const operation = computed<AiOperation>(() => (mode.value === 'improve' ? improveOp.value : mode.value));
const hasSource = computed(() => {
    const has = (value: string): boolean => value.trim() !== '';

    return mode.value === 'improve' ? has(body.value) : has(notes.value) || has(body.value) || has(props.title);
});
const translateLanguageOk = computed(() => operation.value !== 'translate' || language.value !== 'same');
const canRun = computed(() => canAssist.value && hasSource.value && translateLanguageOk.value && !loading.value);
const sourceHint = computed(() => {
    if (mode.value === 'improve') {
        return t('socialAi.needsText');
    }

    return t('socialAi.needsSomething');
});

const resultLength = computed(() => Array.from(result.value?.text ?? '').length);
const overLimit = computed(() => result.value !== null && result.value.maxLength > 0 && resultLength.value > result.value.maxLength);
const remainingSlots = computed(() => MAX_HASHTAGS - extraTags.value.length);
const stateKey = computed(() => (result.value === null || result.value.state === 'ok' ? null : result.value.state));
const isProblem = computed(() => stateKey.value !== null);

function stateText(kind: 'title' | 'body'): string {
    const state = stateKey.value;

    return state === null ? '' : t(`socialAi.states.${state}.${kind}`);
}

watch([mode, improveOp], () => {
    result.value = null;
    applied.value = null;
});

watch(improveOp, (value) => {
    // Translating needs a concrete target; "same" makes no sense there.
    if (value === 'translate' && language.value === 'same') {
        language.value = 'en';
    }
});

async function run(retry = false): Promise<void> {
    if (!canRun.value) {
        return;
    }

    const key = operation.value;
    attempts.value[key] = retry ? (attempts.value[key] ?? 1) + 1 : (attempts.value[key] ?? 1);
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    applied.value = null;
    copied.value = false;

    const suggestion = await requestSuggestion(
        route('social.posts.ai', { operation: key }),
        key,
        {
            title: props.title.trim(),
            notes: mode.value === 'caption' || mode.value === 'hashtags' ? notes.value.trim() : '',
            body: body.value,
            link_url: props.linkUrl.trim(),
            tone: tone.value,
            language: language.value,
            count: Number(count.value),
            attempt: attempts.value[key] ?? 1,
            account_ids: props.accountIds,
        },
        controller.signal,
    );

    if (controller.signal.aborted) {
        return;
    }

    loading.value = false;
    result.value = suggestion;
    picked.value = suggestion.state === 'ok' ? [...suggestion.hashtags] : [];
}

function useText(): void {
    if (result.value === null || result.value.text === '') {
        return;
    }

    previousBody.value = body.value;
    body.value = result.value.text;
    marked.value = true;
    applied.value = 'text';
}

function undo(): void {
    if (previousBody.value !== null) {
        body.value = previousBody.value;
        previousBody.value = null;
        applied.value = null;
    }
}

function addTags(): void {
    const chosen = picked.value.filter((tag) => result.value?.hashtags.includes(tag));

    if (chosen.length === 0) {
        return;
    }

    extraTags.value = mergeTags(extraTags.value, chosen);
    marked.value = true;
    applied.value = 'tags';
}

function togglePicked(tag: string, on: boolean): void {
    picked.value = on ? [...picked.value.filter((item) => item !== tag), tag] : picked.value.filter((item) => item !== tag);
}

async function copy(): Promise<void> {
    const text = result.value?.hashtags.length ? picked.value.join(' ') : (result.value?.text ?? '');

    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = false; }, 2500);
    } catch {
        copied.value = false;
    }
}

onBeforeUnmount(() => {
    controller?.abort();
    clearTimeout(copiedTimer);
});
</script>

<template>
    <Card v-if="canAssist" :title="t('socialAi.title')" :description="t('socialAi.description')" data-testid="ai-panel">
        <div class="grid gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <SegmentedControl v-model="mode" :options="modes" :label="t('socialAi.modesLabel')" />
                <Badge v-if="marked" tone="info" data-testid="ai-marked">
                    <Sparkles class="size-3.5" aria-hidden="true" />
                    {{ t('socialAi.markedDraft') }}
                </Badge>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <Textarea
                    v-if="mode !== 'improve'"
                    v-model="notes"
                    class="sm:col-span-2"
                    :label="t('socialAi.notes')"
                    :hint="t('socialAi.notesHint')"
                    :rows="3"
                    maxlength="2000"
                    optional
                />
                <Select v-if="mode === 'improve'" v-model="improveOp" :options="improveOptions" :label="t('socialAi.improve.label')" />
                <Select
                    v-if="mode !== 'hashtags' && !(mode === 'improve' && improveOp === 'shorten')"
                    v-model="tone"
                    :options="toneOptions"
                    :label="t('socialAi.tone')"
                    :disabled="mode === 'improve' && improveOp === 'translate'"
                />
                <Select
                    v-if="!(mode === 'improve' && improveOp === 'shorten')"
                    v-model="language"
                    :options="mode === 'improve' && improveOp === 'translate' ? translateOptions : languageOptions"
                    :label="mode === 'improve' && improveOp === 'translate' ? t('socialAi.translateTo') : t('socialAi.language')"
                />
                <Select v-if="mode === 'hashtags'" v-model="count" :options="countOptions" :label="t('socialAi.count')" />
            </div>

            <p class="flex items-start gap-2 text-xs text-ink-muted">
                <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <span>{{ mode === 'improve' && improveOp === 'shorten' ? t('socialAi.lengthHintShorten') : t('socialAi.lengthHint') }} {{ t('socialAi.linkNote') }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="primary" size="sm" :disabled="!canRun" :loading="loading" data-testid="ai-run" @click="run(false)">
                    <template #icon><Sparkles class="size-4" aria-hidden="true" /></template>
                    {{ t(`socialAi.run.${operation}`) }}
                </Button>
                <p v-if="!hasSource" class="text-xs text-ink-subtle" data-testid="ai-needs">{{ sourceHint }}</p>
            </div>

            <div aria-live="polite" class="grid gap-3" data-testid="ai-result-region">
                <p v-if="loading" class="flex items-center gap-2 text-[0.8125rem] text-ink-muted"><Spinner class="size-4" /> {{ t('socialAi.working') }}</p>

                <div v-else-if="isProblem" class="rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem]" role="status" :data-state="stateKey" data-testid="ai-problem">
                    <p class="font-medium text-warn">{{ stateText('title') }}</p>
                    <p class="mt-1 text-ink-muted">{{ result?.state === 'invalid' && result.detail ? result.detail : stateText('body') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <Button v-if="result?.retryable" size="sm" @click="run(false)">
                            <template #icon><RefreshCw class="size-4" aria-hidden="true" /></template>
                            {{ t('socialAi.tryAgain') }}
                        </Button>
                        <Link v-if="result?.settingsUrl" :href="result.settingsUrl" class="text-brand-text hover:underline" data-testid="ai-settings-link">
                            {{ t('socialAi.openSettings') }}
                        </Link>
                    </div>
                </div>

                <section v-else-if="result && result.state === 'ok'" class="rounded-card border border-line p-4" :aria-label="t('socialAi.suggestion')" data-testid="ai-suggestion">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <Badge tone="info"><Sparkles class="size-3.5" aria-hidden="true" />{{ t('socialAi.generated') }}</Badge>
                        <span v-if="result.text !== ''" :class="['tabular text-xs', overLimit ? 'text-danger' : 'text-ink-subtle']" data-testid="ai-length">
                            {{ t('social.form.length', { count: format.number(resultLength), max: format.number(result.maxLength) }) }}
                        </span>
                    </div>

                    <template v-if="result.hashtags.length > 0">
                        <p class="mb-2 text-xs text-ink-muted">{{ t('socialAi.pickTags') }}</p>
                        <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1" data-testid="ai-tags">
                            <li v-for="tag in result.hashtags" :key="tag">
                                <Checkbox :model-value="picked.includes(tag)" :label="tag" @update:model-value="togglePicked(tag, $event)" />
                            </li>
                        </ul>
                        <p v-if="remainingSlots <= 0" class="mb-2 text-xs text-warn" role="status">{{ t('socialAi.tagsFull', { max: format.number(MAX_HASHTAGS) }) }}</p>
                    </template>
                    <p v-else-if="result.text !== ''" class="mb-3 text-[0.9375rem] break-words whitespace-pre-wrap text-ink" data-testid="ai-text">{{ result.text }}</p>
                    <p v-else class="mb-3 text-[0.8125rem] text-ink-muted">{{ t('socialAi.empty') }}</p>

                    <p v-if="overLimit" class="mb-2 text-xs text-warn" role="status">{{ t('socialAi.overLimit') }}</p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="result.hashtags.length > 0" variant="primary" size="sm" :disabled="picked.length === 0 || remainingSlots <= 0" data-testid="ai-accept" @click="addTags">
                            <template #icon><Hash class="size-4" aria-hidden="true" /></template>
                            {{ t('socialAi.addTags', { count: format.number(picked.length) }) }}
                        </Button>
                        <Button v-else-if="result.text !== ''" variant="primary" size="sm" data-testid="ai-accept" @click="useText">
                            <template #icon><Check class="size-4" aria-hidden="true" /></template>
                            {{ t('socialAi.useText') }}
                        </Button>
                        <Button size="sm" :loading="loading" data-testid="ai-regenerate" @click="run(true)">
                            <template #icon><RefreshCw class="size-4" aria-hidden="true" /></template>
                            {{ t('socialAi.regenerate') }}
                        </Button>
                        <Button size="sm" variant="ghost" data-testid="ai-copy" @click="copy">
                            <template #icon><Copy class="size-4" aria-hidden="true" /></template>
                            {{ copied ? t('socialAi.copied') : t('socialAi.copy') }}
                        </Button>
                        <Button v-if="applied === 'text' && previousBody !== null" size="sm" variant="ghost" @click="undo">
                            <template #icon><Undo2 class="size-4" aria-hidden="true" /></template>
                            {{ t('socialAi.undo') }}
                        </Button>
                    </div>

                    <p v-if="applied" class="mt-2 text-xs text-ok" role="status" data-testid="ai-applied">
                        {{ applied === 'text' ? t('socialAi.appliedText') : t('socialAi.appliedTags') }}
                    </p>
                    <p v-if="result.hashtags.length > 0 && canSaveSet" class="mt-2 text-xs text-ink-muted">
                        {{ t('socialAi.saveSetNote') }}
                        <Link :href="route('social.library.index')" class="text-brand-text hover:underline">{{ t('socialAi.saveSetLink') }}</Link>
                    </p>
                    <p class="mt-3 text-xs text-ink-subtle">{{ t('socialAi.reviewNote') }}</p>
                </section>
            </div>
        </div>
    </Card>
</template>

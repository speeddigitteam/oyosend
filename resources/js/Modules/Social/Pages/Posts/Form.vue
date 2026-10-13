<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Film } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { mergeTags } from '../../Components/aiAssist';
import AiAssistPanel from '../../Components/AiAssistPanel.vue';
import ExtraHashtags from '../../Components/ExtraHashtags.vue';
import MediaCompatBadges from '../../Components/MediaCompatBadges.vue';
import PostTypePicker from '../../Components/PostTypePicker.vue';
import { clockParts, requestPreflight, typeAvailability } from '../../Components/preflight';
import { displayTag, isLocalDateTime, lengthState } from '../../Components/socialFormat';
import type { AccountOption, FormOptions, PostDetail, PostPreview, PostType } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ post: PostDetail | null; form: FormOptions }>();

const { t } = useI18n();
const format = useFormat();

const options = computed(() => props.form);
const editing = computed(() => props.post !== null);

/** Hashtags on the saved post that its hashtag set does not explain: the ones added from AI suggestions. */
function initialExtraTags(): string[] {
    const set = props.form.hashtagSets.find((item) => item.id === props.post?.hashtagSetId);
    const known = new Set((set?.tags ?? []).map((tag) => tag.toLowerCase()));

    return (props.post?.hashtags ?? []).filter((tag) => !known.has(tag.toLowerCase()));
}

const draft = useForm({
    post_type: (props.post?.postType ?? 'post') as PostType,
    title: props.post?.title ?? '',
    body: props.post?.body ?? '',
    link_url: props.post?.linkUrl ?? '',
    scheduled_for: props.post?.scheduledForLocal ?? '',
    campaign_id: props.post?.campaign?.id ?? '',
    category_id: props.post?.category?.id ?? '',
    hashtag_set_id: props.post?.hashtagSetId ?? '',
    account_ids: (props.post?.targets ?? []).map((target) => target.accountId),
    overrides: Object.fromEntries((props.post?.targets ?? []).map((target) => [target.accountId, target.bodyOverride ?? ''])) as Record<string, string>,
    media_ids: (props.post?.media ?? []).map((item) => item.id),
    extra_hashtags: initialExtraTags(),
    ai_assisted: props.post?.aiAssisted ?? false,
});

const campaignOptions = computed(() => [{ value: '', label: t('social.common.none') }, ...options.value.campaigns.map((item) => ({ value: item.id, label: item.name }))]);
const categoryOptions = computed(() => [{ value: '', label: t('social.common.none') }, ...options.value.categories.map((item) => ({ value: item.id, label: item.name }))]);
const hashtagOptions = computed(() => [
    { value: '', label: t('social.common.none') },
    ...options.value.hashtagSets.map((set) => ({ value: set.id, label: set.name })),
]);

const chosenTags = computed<string[]>(() => {
    const set = options.value.hashtagSets.find((item) => item.id === draft.hashtag_set_id);

    return mergeTags(set ? set.tags : [], draft.extra_hashtags);
});

const mediaById = computed(() => new Map(options.value.media.map((item) => [item.id, item])));
const chosenMedia = computed(() => draft.media_ids.map((id) => mediaById.value.get(id)).filter((item) => item !== undefined));
const POST_TYPES: PostType[] = ['post', 'photos', 'video', 'reel'];
const wantsVideo = computed(() => draft.post_type === 'video' || draft.post_type === 'reel');
/** A video or Reel is exactly one file; photos are limited by the strictest chosen account. */
const maxMedia = computed(() => {
    if (wantsVideo.value) {
        return 1;
    }

    const caps = chosenAccounts.value.map((account) => account.capabilities.maxMedia ?? 0).filter((value) => value > 0);

    return Math.min(options.value.limits.maxMedia, ...caps);
});
const mediaFull = computed(() => draft.media_ids.length >= maxMedia.value);
const chosenAccounts = computed(() => options.value.accounts.filter((account) => draft.account_ids.includes(account.id)));
const chosenPlatforms = computed(() => [...new Set(chosenAccounts.value.map((account) => account.platform))]);
const scheduleInvalid = computed(() => draft.scheduled_for !== '' && !isLocalDateTime(draft.scheduled_for));
const empty = computed(() => draft.body.trim() === '' && draft.media_ids.length === 0);

/** For each post type: null when every chosen account can publish it, otherwise the reason. */
const typeReasons = computed<Record<PostType, string | null>>(() => {
    const pool = chosenAccounts.value.length > 0 ? chosenAccounts.value : options.value.accounts.filter((account) => account.usable);
    const missing = typeAvailability(pool);
    const reasons = {} as Record<PostType, string | null>;

    for (const type of POST_TYPES) {
        if (type === 'post') {
            reasons[type] = null;
        } else if (chosenAccounts.value.length > 0) {
            reasons[type] = missing[type].length === 0 ? null : t('socialPublishing.postType.notFor', { accounts: missing[type].join(', ') });
        } else {
            reasons[type] = pool.some((account) => (account.postTypes ?? ['post']).includes(type)) ? null : t('socialPublishing.postType.noAccount');
        }
    }

    return reasons;
});

/** Why a library file cannot be chosen right now, or null. A chosen file can always be removed. */
function mediaBlocked(item: { id: string; kind: string }): string | null {
    if (draft.media_ids.includes(item.id)) {
        return null;
    }

    if (wantsVideo.value && item.kind !== 'video') {
        return t('socialPublishing.media.needsVideo');
    }

    if (draft.post_type === 'photos' && item.kind === 'video') {
        return t('socialPublishing.media.needsPhoto');
    }

    return mediaFull.value ? t('socialPublishing.media.full', { max: format.number(maxMedia.value) }) : null;
}

// Live check: the server runs the same rules as scheduling, without saving anything.
const previews = ref<PostPreview[] | null>([]);
const checking = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function runPreflight(): Promise<void> {
    controller?.abort();
    const current = new AbortController();
    controller = current;

    if (draft.account_ids.length === 0) {
        previews.value = [];
        checking.value = false;

        return;
    }

    checking.value = true;
    const result = await requestPreflight(route('social.posts.preflight'), {
        post_type: draft.post_type,
        body: draft.body,
        link_url: draft.link_url.trim(),
        hashtag_set_id: draft.hashtag_set_id,
        targets: draft.account_ids.map((accountId) => ({
            account_id: accountId,
            body_override: (draft.overrides[accountId] ?? '').trim() === '' ? null : (draft.overrides[accountId] ?? '').trim(),
        })),
        media_ids: draft.media_ids,
    }, current.signal);

    if (current.signal.aborted) {
        return;
    }

    previews.value = result;
    checking.value = false;
}

watch(
    () => [draft.post_type, draft.body, draft.link_url, draft.hashtag_set_id, draft.account_ids, draft.overrides, draft.media_ids],
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => void runPreflight(), 350);
    },
    { deep: true },
);
onMounted(() => void runPreflight());
onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

/** `m:ss` with the workspace's numerals. */
function clock(ms: number): string {
    const { minutes, seconds } = clockParts(ms);

    return `${format.number(minutes)}:${seconds < 10 ? format.number(0) : ''}${format.number(seconds)}`;
}

function previewFor(accountId: string): PostPreview | undefined {
    return previews.value?.find((item) => item.accountId === accountId);
}

interface CheckItem {
    key: string;
    state: 'ok' | 'problem' | 'note';
    text: string;
}

/** What stands between this draft and scheduling, in plain words. Mirrors the server's own rules. */
const checklist = computed<CheckItem[]>(() => {
    const items: CheckItem[] = [
        empty.value
            ? { key: 'content', state: 'problem', text: t('socialPublishing.checklist.noContent') }
            : { key: 'content', state: 'ok', text: t('socialPublishing.checklist.content') },
        draft.account_ids.length === 0
            ? { key: 'accounts', state: 'problem', text: t('socialPublishing.checklist.noAccounts') }
            : { key: 'accounts', state: 'ok', text: t('socialPublishing.checklist.accounts', { count: format.number(draft.account_ids.length) }) },
    ];

    if (draft.account_ids.length > 0) {
        if (previews.value === null) {
            items.push({ key: 'fit', state: 'note', text: t('socialPublishing.checklist.unavailable') });
        } else if (checking.value && previews.value.length === 0) {
            items.push({ key: 'fit', state: 'note', text: t('socialPublishing.checklist.checking') });
        } else {
            const blocked = previews.value.filter((item) => item.issues.length > 0).length;
            const notes = previews.value.reduce((sum, item) => sum + (item.warnings?.length ?? 0), 0);
            items.push(blocked === 0
                ? { key: 'fit', state: 'ok', text: t('socialPublishing.checklist.fits') }
                : { key: 'fit', state: 'problem', text: t('socialPublishing.checklist.blocked', { count: format.number(blocked) }) });

            if (notes > 0) {
                items.push({ key: 'notes', state: 'note', text: t('socialPublishing.checklist.notes', { count: format.number(notes) }) });
            }
        }
    }

    items.push(draft.scheduled_for === ''
        ? { key: 'plan', state: 'note', text: t('socialPublishing.checklist.noPlan') }
        : { key: 'plan', state: 'ok', text: t('socialPublishing.checklist.plan') });

    return items;
});
const ready = computed(() => checklist.value.every((item) => item.state !== 'problem'));

/** Why an account cannot be chosen, or null when it can. */
function unusableReason(account: AccountOption): string | null {
    if (account.usable) {
        return null;
    }

    const platform = options.value.platforms.find((item) => item.key === account.platform);

    if (platform && !platform.available) {
        return platform.reason ?? t('social.form.platformUnavailable');
    }

    if (account.status === 'needs_reauthorization') {
        return t('social.form.needsReauthorization');
    }

    return t('social.form.cannotPublish');
}

function isSelected(id: string): boolean {
    return draft.account_ids.includes(id);
}

function toggleAccount(id: string, on: boolean): void {
    draft.account_ids = on
        ? [...draft.account_ids.filter((item) => item !== id), id]
        : draft.account_ids.filter((item) => item !== id);
}

function toggleMedia(id: string, on: boolean): void {
    draft.media_ids = on
        ? [...draft.media_ids.filter((item) => item !== id), id]
        : draft.media_ids.filter((item) => item !== id);
}

/** What would be sent for this account: its variant or the main text, then the hashtags. */
function composed(accountId: string): string {
    const override = (draft.overrides[accountId] ?? '').trim();
    const text = (override !== '' ? override : draft.body).trim();

    return chosenTags.value.length > 0 ? `${text}\n\n${chosenTags.value.join(' ')}`.trim() : text;
}

function lengthOf(accountId: string): number {
    return Array.from(composed(accountId)).length;
}

function limitOf(account: AccountOption): number {
    return account.capabilities.maxText ?? 0;
}

function stateClass(account: AccountOption): string {
    const state = lengthState(lengthOf(account.id), limitOf(account));

    return state === 'over' ? 'text-danger' : state === 'near' ? 'text-warn' : 'text-ink-subtle';
}

function mediaWarning(account: AccountOption): string | null {
    const count = draft.media_ids.length;

    if (count === 0) {
        return null;
    }

    const max = account.capabilities.maxMedia ?? 0;

    if (max === 0) {
        return t('social.form.noMediaForPlatform', { platform: account.platformLabel });
    }

    if (count > max) {
        return t('social.form.tooMuchMedia', { platform: account.platformLabel, max: format.number(max) });
    }

    if (chosenMedia.value.some((item) => item.kind === 'video') && !account.capabilities.video) {
        return t('social.form.noVideoForPlatform', { platform: account.platformLabel });
    }

    return null;
}

const typeErrors = computed(() => (draft.errors.post_type ? [draft.errors.post_type] : []));
const targetErrors = computed(() => Object.entries(draft.errors)
    .filter(([key]) => key === 'targets' || key.startsWith('targets.'))
    .map(([, message]) => message));
const mediaErrors = computed(() => Object.entries(draft.errors)
    .filter(([key]) => key === 'media_ids' || key.startsWith('media_ids.'))
    .map(([, message]) => message));

function submit(): void {
    draft.transform((data) => ({
        post_type: data.post_type,
        title: data.title.trim(),
        body: data.body,
        link_url: data.link_url.trim(),
        scheduled_for: data.scheduled_for,
        campaign_id: data.campaign_id,
        category_id: data.category_id,
        hashtag_set_id: data.hashtag_set_id,
        targets: data.account_ids.map((accountId) => ({
            account_id: accountId,
            body_override: (data.overrides[accountId] ?? '').trim() === '' ? null : (data.overrides[accountId] ?? '').trim(),
        })),
        media_ids: data.media_ids,
        extra_hashtags: data.extra_hashtags,
        ai_assisted: data.ai_assisted,
    }));

    if (props.post) {
        draft.put(route('social.posts.update', { post: props.post.id }), { preserveScroll: true });
    } else {
        draft.post(route('social.posts.store'), { preserveScroll: true });
    }
}

const cancelHref = computed(() => (props.post ? route('social.posts.show', { post: props.post.id }) : route('social.posts.index')));
const returnsToDraft = computed(() => props.post !== null && ['pending_approval', 'approved', 'scheduled'].includes(props.post.status));
</script>

<template>
    <PageHeader
        :eyebrow="t('social.eyebrow')"
        :title="editing ? t('social.form.editTitle') : t('social.form.createTitle')"
        :description="t('social.form.description', { timezone: options.timezone })"
    />

    <p v-if="returnsToDraft" class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="returns-to-draft">
        {{ t('social.form.returnsToDraft') }}
    </p>

    <form class="grid gap-3" novalidate @submit.prevent="submit">
        <Card :title="t('social.form.content')">
            <div class="grid gap-4">
                <PostTypePicker v-model="draft.post_type" :availability="typeReasons" />
                <p v-for="message in typeErrors" :key="message" class="text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
                <Input v-model="draft.title" :label="t('social.fields.title')" :hint="t('social.form.titleHint')" maxlength="160" optional :error="draft.errors.title" />
                <Textarea
                    v-model="draft.body"
                    :label="t('social.fields.body')"
                    :rows="6"
                    :maxlength="options.limits.maxBody"
                    :error="draft.errors.body"
                />
                <Input v-model="draft.link_url" type="url" :label="t('social.fields.link')" :hint="t('social.form.linkHint')" maxlength="2048" optional :error="draft.errors.link_url" />
            </div>
        </Card>

        <AiAssistPanel
            v-model:body="draft.body"
            v-model:extra-tags="draft.extra_hashtags"
            v-model:marked="draft.ai_assisted"
            :title="draft.title"
            :link-url="draft.link_url"
            :account-ids="draft.account_ids"
        />

        <Card :title="t('social.form.accounts')" :description="t('social.form.accountsHint')">
            <p v-if="options.accounts.length === 0" class="text-[0.8125rem] text-ink-muted" data-testid="no-accounts">
                {{ t('social.form.noAccounts') }}
                <Link :href="route('social.accounts.index')" class="text-brand-text hover:underline">{{ t('social.form.goToAccounts') }}</Link>
            </p>

            <ul v-else class="grid gap-3">
                <li v-for="account in options.accounts" :key="account.id" class="rounded-control border border-line p-3">
                    <Checkbox
                        :model-value="isSelected(account.id)"
                        :disabled="!account.usable && !isSelected(account.id)"
                        :label="`${account.name} (${account.platformLabel})`"
                        :description="unusableReason(account) ?? account.handle ?? undefined"
                        @update:model-value="toggleAccount(account.id, $event)"
                    />
                    <div v-if="isSelected(account.id)" class="mt-3 grid gap-2 pl-7">
                        <Textarea
                            v-model="draft.overrides[account.id]"
                            :label="t('social.form.variant')"
                            :hint="t('social.form.variantHint')"
                            :rows="3"
                            optional
                        />
                        <p :class="['tabular text-xs', stateClass(account)]" data-testid="length">
                            <template v-if="limitOf(account) > 0">
                                {{ t('social.form.length', { count: format.number(lengthOf(account.id)), max: format.number(limitOf(account)) }) }}
                            </template>
                            <template v-else>{{ t('social.form.lengthNoLimit', { count: format.number(lengthOf(account.id)) }) }}</template>
                        </p>
                        <div class="rounded-control bg-hover px-3 py-2" data-testid="account-preview">
                            <p class="eyebrow">{{ t('socialPublishing.preview.title', { type: t(`socialPublishing.postType.${draft.post_type}`) }) }}</p>
                            <p v-if="composed(account.id) !== ''" class="mt-1 text-[0.8125rem] break-words whitespace-pre-wrap text-ink">{{ composed(account.id) }}</p>
                            <p v-if="draft.link_url.trim() !== ''" class="mt-1 text-xs break-all text-ink-muted">{{ draft.link_url.trim() }}</p>
                            <p v-if="chosenMedia.length > 0" class="mt-1 text-xs text-ink-muted">
                                {{ t('socialPublishing.preview.media', { count: format.number(chosenMedia.length) }) }}
                            </p>
                            <p v-if="composed(account.id) === '' && chosenMedia.length === 0" class="mt-1 text-xs text-ink-subtle">{{ t('socialPublishing.preview.empty') }}</p>
                        </div>
                        <template v-if="previewFor(account.id)">
                            <ul v-if="previewFor(account.id)!.issues.length > 0" class="grid gap-1 text-xs text-danger" role="alert" data-testid="account-issues">
                                <li v-for="issue in previewFor(account.id)!.issues" :key="issue">{{ issue }}</li>
                            </ul>
                            <ul v-if="(previewFor(account.id)!.warnings ?? []).length > 0" class="grid gap-1 text-xs text-warn" role="status" data-testid="account-warnings">
                                <li v-for="warning in previewFor(account.id)!.warnings" :key="warning">{{ warning }}</li>
                            </ul>
                        </template>
                        <p v-else-if="previews === null && mediaWarning(account)" class="text-xs text-warn" role="status">{{ mediaWarning(account) }}</p>
                        <p v-if="!account.usable" class="text-xs text-warn" role="status">{{ unusableReason(account) }}</p>
                    </div>
                </li>
            </ul>

            <p v-for="message in targetErrors" :key="message" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
        </Card>

        <Card :title="t('social.form.media')" :description="t('social.form.mediaHint', { max: format.number(maxMedia) })">
            <p v-if="options.media.length === 0" class="text-[0.8125rem] text-ink-muted" data-testid="no-media">
                {{ t('social.form.noMedia') }}
                <Link :href="route('social.media.index')" class="text-brand-text hover:underline">{{ t('social.form.goToMedia') }}</Link>
            </p>
            <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="item in options.media" :key="item.id" class="min-w-0 rounded-control border border-line p-2">
                    <div class="mb-2 flex aspect-video items-center justify-center overflow-hidden rounded-control bg-hover">
                        <img v-if="item.kind === 'image'" :src="item.url" :alt="item.altText ?? item.name" loading="lazy" class="size-full object-cover" />
                        <Film v-else class="size-6 text-ink-subtle" aria-hidden="true" />
                    </div>
                    <Checkbox
                        :model-value="draft.media_ids.includes(item.id)"
                        :disabled="mediaBlocked(item) !== null"
                        :label="item.name"
                        :description="mediaBlocked(item) ?? undefined"
                        @update:model-value="toggleMedia(item.id, $event)"
                    />
                    <p v-if="item.kind === 'video' && item.durationMs" class="tabular mt-1 text-xs text-ink-subtle">
                        {{ t('socialPublishing.media.length', { value: clock(item.durationMs) }) }}
                    </p>
                    <MediaCompatBadges v-if="item.compat" class="mt-1.5" :compat="item.compat" :platforms="chosenPlatforms" :max="4" />
                </li>
            </ul>
            <p v-for="message in mediaErrors" :key="message" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
        </Card>

        <Card :title="t('social.form.planning')">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input
                    v-model="draft.scheduled_for"
                    type="datetime-local"
                    :label="t('social.form.plannedFor', { timezone: options.timezone })"
                    :hint="t('social.form.plannedForHint')"
                    optional
                    :error="draft.errors.scheduled_for ?? (scheduleInvalid ? t('social.form.plannedInvalid') : undefined)"
                />
                <Select v-model="draft.campaign_id" :options="campaignOptions" :label="t('social.fields.campaign')" :error="draft.errors.campaign_id" />
                <Select v-model="draft.category_id" :options="categoryOptions" :label="t('social.fields.category')" :error="draft.errors.category_id" />
                <div>
                    <Select
                        v-model="draft.hashtag_set_id"
                        :options="hashtagOptions"
                        :label="t('social.fields.hashtagSet')"
                        :hint="t('social.form.hashtagHint')"
                        :error="draft.errors.hashtag_set_id"
                    />
                    <p v-if="chosenTags.length > 0" class="mt-2 text-xs break-words text-ink-muted" data-testid="chosen-tags">
                        {{ chosenTags.map(displayTag).join(' ') }}
                    </p>
                    <ExtraHashtags v-model="draft.extra_hashtags" class="mt-2" />
                </div>
            </div>
        </Card>

        <Card :title="t('socialPublishing.checklist.title')" :description="ready ? t('socialPublishing.checklist.ready') : t('socialPublishing.checklist.notReady')">
            <ul class="grid gap-2" aria-live="polite" data-testid="checklist">
                <li v-for="item in checklist" :key="item.key" class="flex items-start gap-2 text-[0.8125rem]" :data-state="item.state">
                    <CircleCheck v-if="item.state === 'ok'" class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />
                    <CircleAlert v-else :class="['mt-0.5 size-4 shrink-0', item.state === 'problem' ? 'text-danger' : 'text-ink-subtle']" aria-hidden="true" />
                    <span :class="item.state === 'problem' ? 'text-danger' : 'text-ink-muted'">{{ item.text }}</span>
                </li>
            </ul>
        </Card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <Button variant="ghost" :href="cancelHref">{{ t('social.common.cancel') }}</Button>
            <Button type="submit" variant="primary" :loading="draft.processing" :disabled="empty || scheduleInvalid">
                {{ editing ? t('social.form.saveChanges') : t('social.form.saveDraft') }}
            </Button>
        </div>
    </form>
</template>

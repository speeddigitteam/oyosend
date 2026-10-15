<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, Check, CircleAlert, CircleCheck, Eye, Film, ImagePlus, Play, Plus, Send, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AccountAvatar from '../../Components/AccountAvatar.vue';
import AccountRequirements from '../../Components/AccountRequirements.vue';
import { mergeTags } from '../../Components/aiAssist';
import AiAssistPanel from '../../Components/AiAssistPanel.vue';
import ComposerPreview from '../../Components/ComposerPreview.vue';
import ComposerScheduleDialog from '../../Components/ComposerScheduleDialog.vue';
import ExtraHashtags from '../../Components/ExtraHashtags.vue';
import MediaCompatBadges from '../../Components/MediaCompatBadges.vue';
import PlatformIcon from '../../Components/PlatformIcon.vue';
import PostTypePicker from '../../Components/PostTypePicker.vue';
import { clockParts, requestPreflight, typeAvailability } from '../../Components/preflight';
import { composedText } from '../../Components/preview/postPreview';
import { requirementProblems } from '../../Components/requirements';
import TikTokSettings from '../../Components/TikTokSettings.vue';
import { emptyTikTokOptions, tikTokOptionsFrom, tikTokProblems } from '../../Components/tiktokOptions';
import { displayTag, isLocalDateTime, lengthState } from '../../Components/socialFormat';
import type { AccountOption, FormOptions, PostDetail, PostPreview, PostType, TikTokOptions } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ post: PostDetail | null; form: FormOptions }>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
const toasts = useToasts();

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
    /** The TikTok settings chosen per TikTok account (sent as that target's `options`). */
    tiktok: Object.fromEntries((props.post?.targets ?? [])
        .filter((target) => target.platform === 'tiktok')
        .map((target) => [target.accountId, tikTokOptionsFrom(target.publishOptions)])) as Record<string, TikTokOptions>,
});

function isTikTok(account: { platform: string }): boolean {
    return account.platform === 'tiktok';
}

/** The TikTok settings of an account: what was chosen, or nothing chosen yet. */
function tiktokOf(accountId: string): TikTokOptions {
    return draft.tiktok[accountId] ?? emptyTikTokOptions();
}

/** The `options` sent for an account: its TikTok settings, or null for a platform without any. */
function optionsFor(accountId: string): TikTokOptions | null {
    const account = options.value.accounts.find((item) => item.id === accountId);

    return account && isTikTok(account) ? tiktokOf(accountId) : null;
}

/** Accounts that already had their own wording when the page opened: their wording box starts open. */
const initialOverrides = new Set((props.post?.targets ?? []).filter((target) => (target.bodyOverride ?? '').trim() !== '').map((target) => target.accountId));

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
/** The chosen accounts in the order they were ticked (the preview tabs follow it). */
const chosenAccounts = computed(() => draft.account_ids
    .map((id) => options.value.accounts.find((account) => account.id === id))
    .filter((account) => account !== undefined));
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
            options: optionsFor(accountId),
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
    () => [draft.post_type, draft.body, draft.link_url, draft.hashtag_set_id, draft.account_ids, draft.overrides, draft.media_ids, draft.tiktok],
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

    // TikTok asks the creator to choose these themselves: posting waits until they have.
    const tiktokOpen = chosenAccounts.value
        .filter((account) => isTikTok(account) && account.usable)
        .filter((account) => tikTokProblems(tiktokOf(account.id), null, []).length > 0)
        .map((account) => account.name);

    if (tiktokOpen.length > 0) {
        items.push({ key: 'tiktok', state: 'problem', text: t('social.tiktok.checklist', { accounts: tiktokOpen.join(', ') }) });
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

const unusableReasons = computed<Record<string, string | null>>(() => Object.fromEntries(
    chosenAccounts.value.map((account) => [account.id, unusableReason(account)]),
));

function isSelected(id: string): boolean {
    return draft.account_ids.includes(id);
}

function toggleAccount(id: string, on: boolean): void {
    const account = options.value.accounts.find((item) => item.id === id);

    if (on && account && isTikTok(account) && !draft.tiktok[id]) {
        draft.tiktok = { ...draft.tiktok, [id]: emptyTikTokOptions() };
    }

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
    return composedText(draft.body, draft.overrides[accountId], chosenTags.value);
}

const composedTexts = computed<Record<string, string>>(() => Object.fromEntries(
    chosenAccounts.value.map((account) => [account.id, composed(account.id)]),
));

function lengthOf(accountId: string): number {
    return Array.from(composedTexts.value[accountId] ?? composed(accountId)).length;
}

function limitOf(account: AccountOption): number {
    return account.capabilities.maxText ?? 0;
}

function stateClass(account: AccountOption): string {
    const state = lengthState(lengthOf(account.id), limitOf(account));

    return state === 'over' ? 'text-danger' : state === 'near' ? 'text-warn' : 'text-ink-subtle';
}

function mediaWarning(account: AccountOption): string | null {
    const unmet = requirementProblems(account.capabilities, chosenMedia.value);

    if (unmet.length > 0) {
        return t(`social.requirements.${unmet[0]}`);
    }

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

// The media library opens under the chosen files ("Add more"), with the same selection as before.
const libraryOpen = ref(false);
const libraryId = useId();

/**
 * Submitting for approval, scheduling and publishing use the same actions as the post page; the composer saves
 * the post first and then asks for the next step on the saved post. Both are the member's own permission
 * (`social.posts.schedule`); the server checks it, the account allow-list and the content again.
 */
const canAct = computed(() => can('social.posts.schedule'));
const needsApproval = computed(() => options.value.approvalRequired);
const scheduleOpen = ref(false);
const pending = ref<'draft' | 'submit' | 'schedule' | 'publish' | null>(null);
const busy = computed(() => draft.processing || pending.value !== null);
const savable = computed(() => !empty.value && !scheduleInvalid.value && !busy.value);
const actionable = computed(() => savable.value && ready.value);

type NextStep = { kind: 'submit' } | { kind: 'schedule'; at: string | null };

/** The id of the post the save produced: the edited one, or the new one on the page the store redirected to. */
function savedId(page: unknown): string | null {
    if (props.post) {
        return props.post.id;
    }

    const post = (page as { props?: { post?: { id?: unknown } } } | undefined)?.props?.post;

    return typeof post?.id === 'string' ? post.id : null;
}

function follow(next: NextStep, id: string): void {
    const name = next.kind === 'submit' ? 'social.posts.submit' : 'social.posts.schedule';
    const data = next.kind === 'submit' ? {} : next.at !== null ? { scheduled_for: next.at } : { publish_now: true };

    router.post(route(name, { post: id }), data, {
        preserveScroll: true,
        // The post page has no form for these errors: say them, the post stays a saved draft.
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];

            if (first) {
                toasts.error(first);
            }
        },
        onFinish: () => {
            pending.value = null;
        },
    });
}

function save(next?: NextStep): void {
    pending.value = next === undefined ? 'draft' : next.kind === 'submit' ? 'submit' : next.at === null ? 'publish' : 'schedule';

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
            options: optionsFor(accountId),
        })),
        media_ids: data.media_ids,
        extra_hashtags: data.extra_hashtags,
        ai_assisted: data.ai_assisted,
    }));

    let following = false;
    const visit = {
        preserveScroll: true,
        onSuccess: (page: unknown) => {
            const id = next ? savedId(page) : null;

            if (next && id !== null) {
                following = true;
                follow(next, id);
            }
        },
        onFinish: () => {
            if (!following) {
                pending.value = null;
            }
        },
    };

    if (props.post) {
        draft.put(route('social.posts.update', { post: props.post.id }), visit);
    } else {
        draft.post(route('social.posts.store'), visit);
    }
}

function scheduleAt(when: string): void {
    draft.scheduled_for = when;
    scheduleOpen.value = false;
    save({ kind: 'schedule', at: when });
}

function showPreview(): void {
    const panel = document.getElementById('post-preview');
    panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    panel?.focus({ preventScroll: true });
}

const cancelHref = computed(() => (props.post ? route('social.posts.show', { post: props.post.id }) : route('social.posts.index')));
const returnsToDraft = computed(() => props.post !== null && ['pending_approval', 'approved', 'scheduled'].includes(props.post.status));
</script>

<template>
    <PageHeader
        :eyebrow="t('social.eyebrow')"
        :title="editing ? t('social.form.editTitle') : t('social.composer.createTitle')"
        :description="t('social.form.description', { timezone: options.timezone })"
    >
        <template #actions>
            <Button variant="ghost" :href="cancelHref">{{ t('social.common.cancel') }}</Button>
        </template>
    </PageHeader>

    <p v-if="returnsToDraft" class="mb-3 rounded-card bg-warn-soft px-4 py-3 text-[0.8125rem] text-warn" role="status" data-testid="returns-to-draft">
        {{ t('social.form.returnsToDraft') }}
    </p>

    <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,40rem)_minmax(0,1fr)]">
        <form class="grid min-w-0 gap-3" novalidate @submit.prevent="save()">
            <Card>
                <div class="grid gap-5">
                    <fieldset class="min-w-0" data-testid="account-picker">
                        <legend class="mb-1 text-[0.8125rem] font-medium text-ink">{{ t('social.composer.postTo') }}</legend>
                        <p class="mb-2.5 text-[0.8125rem] text-ink-subtle">{{ t('social.form.accountsHint') }}</p>

                        <p v-if="options.accounts.length === 0" class="text-[0.8125rem] text-ink-muted" data-testid="no-accounts">
                            {{ t('social.form.noAccounts') }}
                            <Link :href="route('social.accounts.index')" class="text-brand-text hover:underline">{{ t('social.form.goToAccounts') }}</Link>
                        </p>

                        <ul v-else class="flex flex-wrap gap-2">
                            <li v-for="account in options.accounts" :key="account.id" class="max-w-full min-w-0">
                                <label
                                    :class="[
                                        'flex max-w-full min-w-0 items-center gap-2.5 rounded-control border py-1.5 pr-3 pl-1.5 transition-colors',
                                        'focus-within:ring-2 focus-within:ring-brand',
                                        isSelected(account.id) ? 'border-brand bg-brand-soft' : 'border-line',
                                        !account.usable && !isSelected(account.id) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-hover',
                                    ]"
                                    :data-account="account.id"
                                >
                                    <input
                                        type="checkbox"
                                        class="sr-only"
                                        :checked="isSelected(account.id)"
                                        :disabled="!account.usable && !isSelected(account.id)"
                                        @change="toggleAccount(account.id, ($event.target as HTMLInputElement).checked)"
                                    />
                                    <span class="relative shrink-0">
                                        <AccountAvatar :account="account" size="sm" />
                                        <span class="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full bg-control text-ink-muted ring-1 ring-line">
                                            <PlatformIcon :platform="account.platform" class="size-2.5" />
                                        </span>
                                    </span>
                                    <span class="grid min-w-0">
                                        <span class="truncate text-[0.8125rem] font-medium text-ink">{{ account.name }}</span>
                                        <span class="truncate text-xs text-ink-subtle">{{ account.platformLabel }}<template v-if="account.handle"> &middot; {{ account.handle }}</template></span>
                                        <span v-if="unusableReason(account)" class="text-xs break-words whitespace-normal text-warn">{{ unusableReason(account) }}</span>
                                    </span>
                                    <Check v-if="isSelected(account.id)" class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                                </label>
                            </li>
                        </ul>

                        <p v-for="message in targetErrors" :key="message" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
                    </fieldset>

                    <Textarea
                        v-model="draft.body"
                        :label="t('social.fields.body')"
                        :placeholder="t('social.composer.bodyPlaceholder')"
                        :rows="7"
                        :maxlength="options.limits.maxBody"
                        :error="draft.errors.body"
                        control-class="min-h-40 text-[0.9375rem] leading-6"
                    >
                        <template #aside>
                            <span class="tabular text-xs text-ink-subtle">{{ t('social.form.lengthNoLimit', { count: format.number(Array.from(draft.body).length) }) }}</span>
                        </template>
                    </Textarea>

                    <div class="grid gap-2">
                        <PostTypePicker v-model="draft.post_type" :availability="typeReasons" />
                        <p v-for="message in typeErrors" :key="message" class="text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
                    </div>

                    <section class="grid min-w-0 gap-2.5" :aria-label="t('social.composer.media')" data-testid="media-section">
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="text-[0.8125rem] font-medium text-ink">{{ t('social.composer.media') }}</h3>
                            <span class="tabular text-xs text-ink-subtle">{{ t('social.composer.mediaCount', { count: format.number(draft.media_ids.length), max: format.number(maxMedia) }) }}</span>
                        </div>

                        <p v-if="options.media.length === 0" class="text-[0.8125rem] text-ink-muted" data-testid="no-media">
                            {{ t('social.form.noMedia') }}
                            <Link :href="route('social.media.index')" class="text-brand-text hover:underline">{{ t('social.form.goToMedia') }}</Link>
                        </p>

                        <ul v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4" data-testid="chosen-media">
                            <li v-for="item in chosenMedia" :key="item.id" class="relative aspect-square min-w-0 overflow-hidden rounded-control bg-hover ring-1 ring-line">
                                <img v-if="item.kind === 'image'" :src="item.url" :alt="item.altText ?? item.name" loading="lazy" class="size-full object-cover" />
                                <div v-else class="flex size-full items-center justify-center">
                                    <Film class="size-6 text-ink-subtle" aria-hidden="true" />
                                    <span class="absolute inset-0 m-auto flex size-8 items-center justify-center rounded-full bg-ink/70 text-canvas" aria-hidden="true">
                                        <Play class="size-3.5" />
                                    </span>
                                    <span class="sr-only">{{ item.name }}</span>
                                    <span v-if="item.durationMs" class="tabular absolute bottom-1 left-1 rounded-chip bg-ink/70 px-1.5 text-[0.6875rem] text-canvas">
                                        <span class="sr-only">{{ t('socialPublishing.media.length', { value: clock(item.durationMs) }) }}</span>
                                        <span aria-hidden="true">{{ clock(item.durationMs) }}</span>
                                    </span>
                                </div>
                                <Button icon size="sm" class="absolute top-1 right-1" @click="toggleMedia(item.id, false)">
                                    <template #icon><X class="size-3.5" aria-hidden="true" /></template>
                                    {{ t('social.composer.removeMedia', { name: item.name }) }}
                                </Button>
                            </li>
                            <li :class="chosenMedia.length === 0 ? 'col-span-3 sm:col-span-4' : ''">
                                <button
                                    type="button"
                                    :class="[
                                        'flex size-full min-h-24 flex-col items-center justify-center gap-1.5 rounded-control border border-dashed border-line-strong p-3',
                                        'text-[0.8125rem] font-medium text-ink-muted transition-colors hover:bg-hover hover:text-ink',
                                        'focus-visible:outline-2 focus-visible:outline-brand',
                                        chosenMedia.length > 0 ? 'aspect-square' : '',
                                    ]"
                                    :aria-expanded="libraryOpen"
                                    :aria-controls="libraryId"
                                    data-testid="add-media"
                                    @click="libraryOpen = !libraryOpen"
                                >
                                    <component :is="chosenMedia.length > 0 ? Plus : ImagePlus" class="size-5" aria-hidden="true" />
                                    {{ chosenMedia.length > 0 ? t('social.composer.addMore') : t('social.composer.addMedia') }}
                                </button>
                            </li>
                        </ul>

                        <div v-show="libraryOpen && options.media.length > 0" :id="libraryId" class="grid gap-3 rounded-control glass-well p-3" data-testid="media-library">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[0.8125rem] font-medium text-ink">{{ t('social.composer.library') }}</p>
                                    <p class="text-xs text-ink-subtle">
                                        {{ t('social.form.mediaHint', { max: format.number(maxMedia) }) }}
                                        <Link :href="route('social.media.index')" class="text-brand-text hover:underline">{{ t('social.composer.uploadNew') }}</Link>
                                    </p>
                                </div>
                                <Button size="sm" variant="ghost" @click="libraryOpen = false">{{ t('social.composer.libraryDone') }}</Button>
                            </div>
                            <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                                <li v-for="item in options.media" :key="item.id" class="min-w-0 rounded-control border border-line bg-control p-2">
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
                        </div>

                        <p v-for="message in mediaErrors" :key="message" class="text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
                    </section>
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

            <Card v-if="chosenAccounts.length > 0" :title="t('social.composer.perAccount')" :description="t('social.composer.perAccountHint')">
                <ul class="divide-y divide-line">
                    <li v-for="account in chosenAccounts" :key="account.id" class="grid gap-2 py-3 first:pt-0 last:pb-0" :data-account-settings="account.id">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-sm font-medium text-ink">{{ account.name }}</span>
                            <p :class="['tabular text-xs', stateClass(account)]" data-testid="length">
                                <template v-if="limitOf(account) > 0">
                                    {{ t('social.form.length', { count: format.number(lengthOf(account.id)), max: format.number(limitOf(account)) }) }}
                                </template>
                                <template v-else>{{ t('social.form.lengthNoLimit', { count: format.number(lengthOf(account.id)) }) }}</template>
                            </p>
                        </div>
                        <AccountRequirements
                            :platform="account.platform"
                            :platform-label="account.platformLabel"
                            :capabilities="account.capabilities"
                            :media="chosenMedia"
                        />
                        <TikTokSettings
                            v-if="isTikTok(account) && account.usable"
                            :account="account"
                            :post-type="draft.post_type"
                            :media="chosenMedia"
                            :model-value="tiktokOf(account.id)"
                            @update:model-value="draft.tiktok = { ...draft.tiktok, [account.id]: $event }"
                        />
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
                        <details class="group" :open="initialOverrides.has(account.id)">
                            <summary class="w-fit cursor-pointer rounded-chip text-[0.8125rem] text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-brand">
                                {{ t('social.composer.ownWording') }}
                            </summary>
                            <div class="mt-2">
                                <Textarea
                                    v-model="draft.overrides[account.id]"
                                    :label="t('social.form.variant')"
                                    :hint="t('social.form.variantHint')"
                                    :rows="3"
                                    optional
                                />
                            </div>
                        </details>
                    </li>
                </ul>
            </Card>

            <Card :title="t('social.composer.options')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <Input
                            v-model="draft.link_url"
                            type="url"
                            :label="t('social.fields.link')"
                            :hint="t('social.form.linkHint')"
                            maxlength="2048"
                            optional
                            :error="draft.errors.link_url"
                        />
                    </div>
                    <div class="sm:col-span-2">
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
                    <Input
                        v-model="draft.scheduled_for"
                        type="datetime-local"
                        :label="t('social.form.plannedFor', { timezone: options.timezone })"
                        :hint="needsApproval && canAct ? t('social.composer.approvalNote') : t('social.form.plannedForHint')"
                        optional
                        :error="draft.errors.scheduled_for ?? (scheduleInvalid ? t('social.form.plannedInvalid') : undefined)"
                    />
                    <Input v-model="draft.title" :label="t('social.fields.title')" :hint="t('social.form.titleHint')" maxlength="160" optional :error="draft.errors.title" />
                    <Select v-model="draft.campaign_id" :options="campaignOptions" :label="t('social.fields.campaign')" :error="draft.errors.campaign_id" />
                    <Select v-model="draft.category_id" :options="categoryOptions" :label="t('social.fields.category')" :error="draft.errors.category_id" />
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

            <div
                class="glass-solid sticky bottom-2 z-10 flex flex-wrap items-center justify-end gap-2 rounded-card px-3 py-2.5"
                role="group"
                :aria-label="t('social.composer.actions')"
                data-testid="composer-actions"
            >
                <Button icon variant="ghost" class="mr-auto xl:hidden" @click="showPreview">
                    <template #icon><Eye class="size-4" aria-hidden="true" /></template>
                    {{ t('social.composer.showPreview') }}
                </Button>
                <Button
                    type="submit"
                    :variant="canAct ? 'secondary' : 'primary'"
                    :loading="pending === 'draft'"
                    :disabled="!savable"
                    data-testid="save-draft"
                >
                    {{ editing ? t('social.form.saveChanges') : t('social.form.saveDraft') }}
                </Button>
                <template v-if="canAct">
                    <Button
                        v-if="needsApproval"
                        variant="primary"
                        :loading="pending === 'submit'"
                        :disabled="!actionable"
                        data-testid="submit-approval"
                        @click="save({ kind: 'submit' })"
                    >
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('social.composer.submitForApproval') }}
                    </Button>
                    <template v-else>
                        <Button :loading="pending === 'schedule'" :disabled="!actionable" data-testid="schedule" @click="scheduleOpen = true">
                            <template #icon><CalendarClock class="size-4" aria-hidden="true" /></template>
                            {{ t('social.composer.schedule') }}
                        </Button>
                        <Button variant="primary" :loading="pending === 'publish'" :disabled="!actionable" data-testid="publish-now" @click="save({ kind: 'schedule', at: null })">
                            <template #icon><Send class="size-4" aria-hidden="true" /></template>
                            {{ t('social.composer.publishNow') }}
                        </Button>
                    </template>
                </template>
            </div>
        </form>

        <aside
            id="post-preview"
            tabindex="-1"
            class="min-w-0 rounded-card focus:outline-none xl:sticky xl:top-20 xl:max-h-[calc(100dvh-6rem)] xl:overflow-y-auto"
            :aria-label="t('social.preview.title')"
        >
            <ComposerPreview
                :accounts="chosenAccounts"
                :texts="composedTexts"
                :media="chosenMedia"
                :link-url="draft.link_url"
                :checks="previews"
                :checking="checking"
                :unusable="unusableReasons"
            />
        </aside>
    </div>

    <ComposerScheduleDialog
        v-model:open="scheduleOpen"
        :initial="draft.scheduled_for"
        :timezone="options.timezone"
        :processing="busy"
        @confirm="scheduleAt"
    />
</template>

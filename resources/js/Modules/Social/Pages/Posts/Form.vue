<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Film } from 'lucide-vue-next';
import { computed } from 'vue';
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
import { displayTag, isLocalDateTime, lengthState } from '../../Components/socialFormat';
import type { AccountOption, FormOptions, PostDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ post: PostDetail | null; form: FormOptions }>();

const { t } = useI18n();
const format = useFormat();

const options = computed(() => props.form);
const editing = computed(() => props.post !== null);

const draft = useForm({
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
});

const campaignOptions = computed(() => [{ value: '', label: t('social.common.none') }, ...options.value.campaigns.map((item) => ({ value: item.id, label: item.name }))]);
const categoryOptions = computed(() => [{ value: '', label: t('social.common.none') }, ...options.value.categories.map((item) => ({ value: item.id, label: item.name }))]);
const hashtagOptions = computed(() => [
    { value: '', label: t('social.common.none') },
    ...options.value.hashtagSets.map((set) => ({ value: set.id, label: set.name })),
]);

const chosenTags = computed<string[]>(() => {
    const set = options.value.hashtagSets.find((item) => item.id === draft.hashtag_set_id);

    return set ? set.tags : (props.post?.hashtags ?? []);
});

const mediaById = computed(() => new Map(options.value.media.map((item) => [item.id, item])));
const chosenMedia = computed(() => draft.media_ids.map((id) => mediaById.value.get(id)).filter((item) => item !== undefined));
const mediaFull = computed(() => draft.media_ids.length >= options.value.limits.maxMedia);
const scheduleInvalid = computed(() => draft.scheduled_for !== '' && !isLocalDateTime(draft.scheduled_for));
const empty = computed(() => draft.body.trim() === '' && draft.media_ids.length === 0);

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

const targetErrors = computed(() => Object.entries(draft.errors)
    .filter(([key]) => key === 'targets' || key.startsWith('targets.'))
    .map(([, message]) => message));
const mediaErrors = computed(() => Object.entries(draft.errors)
    .filter(([key]) => key === 'media_ids' || key.startsWith('media_ids.'))
    .map(([, message]) => message));

function submit(): void {
    draft.transform((data) => ({
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
                        <p v-if="mediaWarning(account)" class="text-xs text-warn" role="status">{{ mediaWarning(account) }}</p>
                        <p v-if="!account.usable" class="text-xs text-warn" role="status">{{ unusableReason(account) }}</p>
                    </div>
                </li>
            </ul>

            <p v-for="message in targetErrors" :key="message" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ message }}</p>
        </Card>

        <Card :title="t('social.form.media')" :description="t('social.form.mediaHint', { max: format.number(options.limits.maxMedia) })">
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
                        :disabled="mediaFull && !draft.media_ids.includes(item.id)"
                        :label="item.name"
                        @update:model-value="toggleMedia(item.id, $event)"
                    />
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
                </div>
            </div>
        </Card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <Button variant="ghost" :href="cancelHref">{{ t('social.common.cancel') }}</Button>
            <Button type="submit" variant="primary" :loading="draft.processing" :disabled="empty || scheduleInvalid">
                {{ editing ? t('social.form.saveChanges') : t('social.form.saveDraft') }}
            </Button>
        </div>
    </form>
</template>

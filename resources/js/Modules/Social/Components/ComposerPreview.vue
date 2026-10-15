<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import Select from '@/Core/Components/Select.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import PlatformIcon from './PlatformIcon.vue';
import FeedPreview from './preview/FeedPreview.vue';
import InstagramPreview from './preview/InstagramPreview.vue';
import PinterestPreview from './preview/PinterestPreview.vue';
import { layoutFor, linkDomain, previewTabs, targetState } from './preview/postPreview';
import TikTokPreview from './preview/TikTokPreview.vue';
import XPreview from './preview/XPreview.vue';
import YouTubePreview from './preview/YouTubePreview.vue';
import type { AccountOption, MediaRow, PostPreview } from './types';

/**
 * The composer's live preview: one tab per platform among the chosen accounts (with an account switcher when
 * several accounts share a platform), each drawn as an approximation of that platform's layout with our own
 * tokens and icons. A target that cannot be published shows why instead of a picture of a post.
 */
const props = defineProps<{
    /** The chosen accounts, in the order they were ticked. */
    accounts: AccountOption[];
    /** The text as it would be published, per account id (its own wording or the main text, plus hashtags). */
    texts: Record<string, string>;
    media: MediaRow[];
    linkUrl: string;
    /** The server check per account; null when it is unavailable. */
    checks: PostPreview[] | null;
    checking: boolean;
    /** Why an account cannot publish, per account id (absent or null when it can). */
    unusable: Record<string, string | null>;
}>();

const { t } = useI18n();

const tabs = computed(() => previewTabs(props.accounts));
const active = ref<string>('');
/** The account shown in each platform's tab, when several share it. */
const picked = reactive<Record<string, string>>({});

watch(tabs, (list) => {
    if (!list.some((tab) => tab.platform === active.value)) {
        active.value = list[0]?.platform ?? '';
    }

    for (const tab of list) {
        if (!tab.accounts.some((account) => account.id === picked[tab.platform])) {
            picked[tab.platform] = tab.accounts[0]!.id;
        }
    }
}, { immediate: true });

const domain = computed(() => linkDomain(props.linkUrl));

const views = computed(() => tabs.value.map((tab) => {
    const account = tab.accounts.find((item) => item.id === picked[tab.platform]) ?? tab.accounts[0]!;
    const server = props.checks?.find((item) => item.accountId === account.id);

    return {
        tab,
        account,
        layout: layoutFor(tab.platform),
        text: props.texts[account.id] ?? '',
        state: targetState(account, props.media, server),
        options: tab.accounts.map((item) => ({ value: item.id, label: item.name })),
    };
}));

function requirementText(key: string): string {
    return t(`social.requirements.${key}`, { value: '' });
}
</script>

<template>
    <Card :title="t('social.preview.title')" :description="t('social.preview.description')" data-testid="post-preview">
        <template v-if="checking" #actions>
            <span class="inline-flex items-center gap-1.5 text-xs text-ink-subtle" role="status">
                <Spinner :size="14" />
                {{ t('socialPublishing.checklist.checking') }}
            </span>
        </template>

        <p v-if="views.length === 0" class="text-sm text-ink-muted" data-testid="preview-none">{{ t('social.preview.none') }}</p>

        <TabsRoot v-else v-model="active" class="min-w-0">
            <TabsList :aria-label="t('social.preview.platforms')" class="mb-3 flex gap-1 overflow-x-auto rounded-control bg-hover p-1">
                <TabsTrigger
                    v-for="view in views"
                    :key="view.tab.platform"
                    :value="view.tab.platform"
                    :data-preview-tab="view.tab.platform"
                    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-control px-3 text-[0.8125rem] font-medium whitespace-nowrap text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-brand data-[state=active]:bg-control data-[state=active]:text-ink"
                >
                    <PlatformIcon :platform="view.tab.platform" class="size-3.5 shrink-0" />
                    {{ view.tab.label }}
                </TabsTrigger>
            </TabsList>

            <TabsContent
                v-for="view in views"
                :key="view.tab.platform"
                :value="view.tab.platform"
                class="grid min-w-0 gap-3 rounded-control focus-visible:outline-2 focus-visible:outline-brand"
                :data-preview-panel="view.tab.platform"
            >
                <Select
                    v-if="view.options.length > 1"
                    v-model="picked[view.tab.platform]"
                    :options="view.options"
                    :label="t('social.preview.account')"
                />

                <div v-if="view.state.blocked" class="grid gap-2 rounded-control border border-warn px-4 py-3 text-[0.8125rem]" role="status" data-testid="preview-blocked">
                    <p class="font-medium text-ink">{{ t('social.preview.blockedTitle', { platform: view.tab.label }) }}</p>
                    <ul class="grid gap-1 text-warn">
                        <li v-if="unusable[view.account.id]">{{ unusable[view.account.id] }}</li>
                        <li v-for="key in view.state.requirements" :key="key" :data-requirement="key">{{ requirementText(key) }}</li>
                        <li v-for="issue in view.state.issues" :key="issue">{{ issue }}</li>
                    </ul>
                </div>

                <template v-else>
                    <div v-if="view.state.issues.length > 0" class="grid gap-1 rounded-control bg-danger-soft px-3 py-2 text-[0.8125rem] text-danger" role="alert" data-testid="preview-issues">
                        <p class="font-medium">{{ t('social.preview.issuesTitle') }}</p>
                        <ul class="grid gap-1">
                            <li v-for="issue in view.state.issues" :key="issue">{{ issue }}</li>
                        </ul>
                    </div>

                    <p v-if="view.text === '' && media.length === 0" class="rounded-control glass-well px-4 py-6 text-center text-[0.8125rem] text-ink-subtle" data-testid="preview-empty">
                        {{ t('socialPublishing.preview.empty') }}
                    </p>
                    <InstagramPreview v-else-if="view.layout === 'instagram'" :account="view.account" :text="view.text" :media="media" />
                    <XPreview v-else-if="view.layout === 'x'" :account="view.account" :text="view.text" :media="media" :domain="domain" />
                    <YouTubePreview v-else-if="view.layout === 'youtube'" :account="view.account" :text="view.text" :media="media" />
                    <TikTokPreview v-else-if="view.layout === 'tiktok'" :account="view.account" :text="view.text" :media="media" />
                    <PinterestPreview v-else-if="view.layout === 'pinterest'" :account="view.account" :text="view.text" :media="media" :domain="domain" />
                    <FeedPreview
                        v-else
                        :layout="view.layout === 'facebook' || view.layout === 'linkedin' ? view.layout : 'feed'"
                        :account="view.account"
                        :text="view.text"
                        :media="media"
                        :domain="domain"
                    />

                    <ul v-if="view.state.warnings.length > 0" class="grid gap-1 text-xs text-warn" role="status" data-testid="preview-warnings">
                        <li v-for="warning in view.state.warnings" :key="warning">{{ warning }}</li>
                    </ul>
                </template>

                <p v-if="checks === null" class="text-xs text-ink-subtle">{{ t('socialPublishing.checklist.unavailable') }}</p>
            </TabsContent>
        </TabsRoot>
    </Card>
</template>

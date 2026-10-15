<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Select from '@/Core/Components/Select.vue';
import Spinner from '@/Core/Components/Spinner.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { fetchCreatorInfo, fitToCreator, isPhotoPost, tikTokProblems } from './tiktokOptions';
import type { AccountOption, CreatorInfo, MediaRow, PostType, TikTokOptions, TikTokPrivacy } from './types';

/**
 * The TikTok settings of one ticked TikTok account, as TikTok's posting guidelines ask: the creator picks who can
 * see the post (nothing is preselected), turns comments / Duet / Stitch on themselves (each only when their TikTok
 * account allows it) and discloses commercial content. The choices come from that account's creator info, read
 * when the card opens; the server checks them again before scheduling and when publishing.
 */
const props = defineProps<{
    account: AccountOption;
    postType: PostType;
    media: MediaRow[];
}>();

const model = defineModel<TikTokOptions>({ required: true });

const { t } = useI18n();
const format = useFormat();
const headingId = useId();

const state = ref<'loading' | 'ok' | 'unavailable'>('loading');
const info = ref<CreatorInfo | null>(null);
const failure = ref<string | null>(null);
/** The previously chosen privacy level is no longer offered and was cleared. */
const privacyGone = ref(false);
let controller: AbortController | undefined;

async function load(): Promise<void> {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    state.value = 'loading';
    failure.value = null;

    const result = await fetchCreatorInfo(route('social.posts.creator_info', { account: props.account.id }), current.signal);

    if (current.signal.aborted) {
        return;
    }

    if (result.state === 'ok') {
        info.value = result.options;
        const fitted = fitToCreator(model.value, result.options);
        privacyGone.value = model.value.privacy_level !== null && fitted.privacy_level === null;

        if (JSON.stringify(fitted) !== JSON.stringify(model.value)) {
            model.value = fitted;
        }

        state.value = 'ok';
    } else {
        info.value = null;
        failure.value = result.message;
        state.value = 'unavailable';
    }
}

onMounted(() => void load());
watch(() => props.account.id, () => void load());
onBeforeUnmount(() => controller?.abort());

function update(patch: Partial<TikTokOptions>): void {
    const next = { ...model.value, ...patch };

    if (!next.disclose_commercial) {
        next.brand_organic = false;
        next.brand_content = false;
    }

    if (patch.privacy_level !== undefined) {
        privacyGone.value = false;
    }

    model.value = next;
}

const photo = computed(() => isPhotoPost(props.postType, props.media));
const problems = computed(() => tikTokProblems(model.value, info.value, props.media));

const privacyOptions = computed(() => (info.value?.privacyLevels ?? []).map((level: TikTokPrivacy) => ({
    value: level,
    label: t(`social.tiktok.privacyLevels.${level}`),
    // TikTok does not allow branded content to be private.
    disabled: level === 'SELF_ONLY' && model.value.brand_content,
})));

const privacy = computed<string>({
    get: () => model.value.privacy_level ?? '',
    set: (value) => update({ privacy_level: (value === '' ? null : value) as TikTokPrivacy | null }),
});

const privacyError = computed(() => {
    if (state.value !== 'ok') {
        return undefined;
    }

    if (problems.value.includes('privacy')) {
        return privacyGone.value ? t('social.tiktok.privacyGone') : t('social.tiktok.privacyRequired');
    }

    return problems.value.includes('brandedPrivate') ? t('social.tiktok.brandedPrivate') : undefined;
});

const MUSIC_URL = 'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en';
const BRANDED_URL = 'https://www.tiktok.com/legal/page/global/bc-policy/en';
</script>

<template>
    <section class="grid gap-3 rounded-control border border-line p-3" :aria-labelledby="headingId" :data-tiktok-settings="account.id">
        <div class="grid gap-0.5">
            <h4 :id="headingId" class="text-[0.8125rem] font-medium text-ink">{{ t('social.tiktok.title') }}</h4>
            <p class="text-xs text-ink-subtle">
                {{ t('social.tiktok.description') }}
                <template v-if="info?.nickname"> {{ t('social.tiktok.postingAs', { name: info.nickname }) }}</template>
            </p>
        </div>

        <p v-if="state === 'loading'" class="inline-flex items-center gap-1.5 text-xs text-ink-subtle" role="status" data-testid="tiktok-loading">
            <Spinner :size="14" />
            {{ t('social.tiktok.loading') }}
        </p>

        <div v-else-if="state === 'unavailable'" class="grid justify-items-start gap-2 text-xs text-warn" role="alert" data-testid="tiktok-unavailable">
            <p>{{ failure ?? t('social.tiktok.unavailable') }}</p>
            <p class="text-ink-subtle">{{ t('social.tiktok.unavailableHint') }}</p>
            <Button size="sm" variant="secondary" @click="load">{{ t('social.tiktok.retry') }}</Button>
        </div>

        <template v-else>
            <Select
                v-model="privacy"
                :options="privacyOptions"
                :label="t('social.tiktok.privacy')"
                :placeholder="t('social.tiktok.privacyPlaceholder')"
                :error="privacyError"
                data-testid="tiktok-privacy"
            />

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-[0.8125rem] font-medium text-ink">{{ t('social.tiktok.interactions') }}</legend>
                <Checkbox
                    :model-value="model.allow_comment"
                    :disabled="info?.commentDisabled"
                    :label="t('social.tiktok.allowComment')"
                    :description="info?.commentDisabled ? t('social.tiktok.disabledByCreator') : undefined"
                    data-testid="tiktok-comment"
                    @update:model-value="update({ allow_comment: $event })"
                />
                <template v-if="!photo">
                    <Checkbox
                        :model-value="model.allow_duet"
                        :disabled="info?.duetDisabled"
                        :label="t('social.tiktok.allowDuet')"
                        :description="info?.duetDisabled ? t('social.tiktok.disabledByCreator') : undefined"
                        data-testid="tiktok-duet"
                        @update:model-value="update({ allow_duet: $event })"
                    />
                    <Checkbox
                        :model-value="model.allow_stitch"
                        :disabled="info?.stitchDisabled"
                        :label="t('social.tiktok.allowStitch')"
                        :description="info?.stitchDisabled ? t('social.tiktok.disabledByCreator') : undefined"
                        data-testid="tiktok-stitch"
                        @update:model-value="update({ allow_stitch: $event })"
                    />
                </template>
            </fieldset>

            <div class="grid gap-2">
                <Checkbox
                    :model-value="model.disclose_commercial"
                    :label="t('social.tiktok.disclose')"
                    :description="t('social.tiktok.discloseHint')"
                    data-testid="tiktok-disclose"
                    @update:model-value="update({ disclose_commercial: $event })"
                />
                <div v-if="model.disclose_commercial" class="grid gap-2 pl-7" data-testid="tiktok-brands">
                    <Checkbox
                        :model-value="model.brand_organic"
                        :label="t('social.tiktok.yourBrand')"
                        :description="t('social.tiktok.yourBrandHint')"
                        data-testid="tiktok-your-brand"
                        @update:model-value="update({ brand_organic: $event })"
                    />
                    <Checkbox
                        :model-value="model.brand_content"
                        :label="t('social.tiktok.brandedContent')"
                        :description="t('social.tiktok.brandedContentHint')"
                        data-testid="tiktok-branded"
                        @update:model-value="update({ brand_content: $event })"
                    />
                    <p v-if="problems.includes('brand')" class="text-xs text-danger" role="alert" data-testid="tiktok-brand-required">{{ t('social.tiktok.brandRequired') }}</p>
                </div>
            </div>

            <p v-if="problems.includes('tooLong') && info?.maxVideoSeconds" class="text-xs text-danger" role="alert" data-testid="tiktok-too-long">
                {{ t('social.tiktok.tooLong', { seconds: format.number(info.maxVideoSeconds) }) }}
            </p>

            <p class="text-xs text-ink-muted" data-testid="tiktok-consent">
                {{ model.brand_content ? t('social.tiktok.consentBranded') : t('social.tiktok.consent') }}
                <a v-if="model.brand_content" :href="BRANDED_URL" target="_blank" rel="noopener noreferrer" class="text-brand-text hover:underline">{{ t('social.tiktok.brandedLink') }}</a>
                <template v-if="model.brand_content"> &middot; </template>
                <a :href="MUSIC_URL" target="_blank" rel="noopener noreferrer" class="text-brand-text hover:underline">{{ t('social.tiktok.musicLink') }}</a>
            </p>
            <p class="text-xs text-ink-subtle">{{ t('social.tiktok.processing') }}</p>
        </template>
    </section>
</template>

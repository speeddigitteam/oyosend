<script setup lang="ts">
import { Clapperboard, FileText, Images, Video } from 'lucide-vue-next';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PostType } from './types';

/**
 * What kind of item the post publishes. A type is offered only when every chosen account supports it;
 * an unavailable type stays visible, disabled, with the reason, so people learn why.
 */
defineProps<{
    /** For each type: null when it can be chosen, otherwise why it cannot. */
    availability: Record<PostType, string | null>;
}>();

const model = defineModel<PostType>({ required: true });
const { t } = useI18n();

const TYPES: { value: PostType; icon: Component }[] = [
    { value: 'post', icon: FileText },
    { value: 'photos', icon: Images },
    { value: 'video', icon: Video },
    { value: 'reel', icon: Clapperboard },
];
</script>

<template>
    <fieldset class="min-w-0">
        <legend class="mb-1.5 text-[0.8125rem] font-medium text-ink">{{ t('socialPublishing.postType.label') }}</legend>
        <div class="grid grid-cols-2 gap-2 min-[560px]:grid-cols-4" role="radiogroup" :aria-label="t('socialPublishing.postType.label')">
            <label
                v-for="type in TYPES"
                :key="type.value"
                :class="[
                    'relative flex min-w-0 cursor-pointer flex-col gap-1 rounded-control border p-3 transition-colors',
                    'focus-within:ring-2 focus-within:ring-brand',
                    model === type.value ? 'border-brand bg-brand-soft' : 'border-line hover:bg-hover',
                    availability[type.value] !== null ? 'cursor-not-allowed opacity-60 hover:bg-transparent' : '',
                ]"
            >
                <input
                    v-model="model"
                    type="radio"
                    name="post_type"
                    class="sr-only"
                    :value="type.value"
                    :disabled="availability[type.value] !== null"
                />
                <span class="flex items-center gap-2 text-sm font-medium text-ink">
                    <component :is="type.icon" class="size-4 shrink-0" aria-hidden="true" />
                    {{ t(`socialPublishing.postType.${type.value}`) }}
                </span>
                <span class="text-xs text-ink-muted">{{ availability[type.value] ?? t(`socialPublishing.postType.hint.${type.value}`) }}</span>
            </label>
        </div>
    </fieldset>
</template>

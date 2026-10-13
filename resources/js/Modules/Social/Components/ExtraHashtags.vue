<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

/** The hashtags added to this post on top of its hashtag set (for example from AI suggestions), each removable. */
const tags = defineModel<string[]>({ required: true });

const { t } = useI18n();

function remove(tag: string): void {
    tags.value = tags.value.filter((item) => item !== tag);
}
</script>

<template>
    <div v-if="tags.length > 0" data-testid="extra-tags">
        <p class="mb-1 text-xs text-ink-muted">{{ t('socialAi.extraTags') }}</p>
        <ul class="flex flex-wrap gap-1.5">
            <li v-for="tag in tags" :key="tag">
                <button
                    type="button"
                    class="inline-flex min-h-7 items-center gap-1 rounded-chip bg-hover px-2.5 text-xs text-ink hover:bg-active"
                    :aria-label="t('socialAi.removeTag', { tag })"
                    @click="remove(tag)"
                >
                    {{ tag }}
                    <X class="size-3.5 text-ink-subtle" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </div>
</template>

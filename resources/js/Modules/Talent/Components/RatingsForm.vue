<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { useTalentFormat } from './talentFormat';
import type { FormItem } from './types';

/**
 * The self or manager step of a review: one rating per competency on the
 * form's scale, an optional comment on each, and an overall comment. Every
 * competency must be rated before the step can be submitted; the score is
 * computed by the server from the weights.
 */
const props = defineProps<{
    reviewId: string;
    party: 'self' | 'manager';
    items: FormItem[];
    scaleMax: number;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const form = useForm({
    ratings: Object.fromEntries(props.items.map((item) => [item.id, { rating: '', comment: '' }])) as Record<string, { rating: string; comment: string }>,
    comment: '',
});

const scale = computed(() => Array.from({ length: props.scaleMax }, (_, index) => ({ value: String(index + 1), label: format.number(index + 1) })));
const complete = computed(() => props.items.every((item) => form.ratings[item.id]?.rating !== ''));
const rated = computed(() => props.items.filter((item) => form.ratings[item.id]?.rating !== '').length);

function submit(): void {
    form.transform((data) => ({
        ratings: Object.fromEntries(props.items.map((item) => {
            const entry = data.ratings[item.id];

            return [item.id, { rating: Number(entry?.rating ?? 0), comment: (entry?.comment ?? '').trim() === '' ? null : (entry?.comment ?? '').trim() }];
        })),
        comment: data.comment.trim() === '' ? null : data.comment.trim(),
    })).post(route(props.party === 'self' ? 'talent.reviews.self' : 'talent.reviews.manager', { review: props.reviewId }), { preserveScroll: true });
}
</script>

<template>
    <form class="grid gap-4" novalidate data-testid="ratings-form" @submit.prevent="submit">
        <div v-for="item in items" :key="item.id" class="grid gap-2 border-b border-line pb-4 last:border-b-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-medium text-ink">{{ item.name }}</p>
                <p class="tabular text-xs text-ink-subtle">{{ t('talent.reviews.weight', { weight: talent.percent(item.weightBp) }) }}</p>
            </div>
            <p v-if="item.description" class="text-xs text-ink-muted">{{ item.description }}</p>
            <SegmentedControl
                v-if="form.ratings[item.id]"
                v-model="form.ratings[item.id]!.rating"
                :options="scale"
                :label="t('talent.reviews.ratingFor', { name: item.name })"
            />
            <Textarea
                v-if="form.ratings[item.id]"
                v-model="form.ratings[item.id]!.comment"
                :label="t('talent.reviews.commentOn')"
                :rows="2"
                maxlength="2000"
                optional
            />
        </div>
        <Textarea v-model="form.comment" :label="t('talent.reviews.overallComment')" maxlength="5000" optional :error="form.errors.comment" />
        <p v-if="form.errors.ratings" class="text-sm text-danger" role="alert">{{ form.errors.ratings }}</p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-ink-subtle" role="status">{{ t('talent.reviews.ratedCount', { done: format.number(rated), total: format.number(items.length) }) }}</p>
            <Button type="submit" variant="primary" :loading="form.processing" :disabled="!complete">
                {{ party === 'self' ? t('talent.reviews.submitSelf') : t('talent.reviews.submitManager') }}
            </Button>
        </div>
    </form>
</template>

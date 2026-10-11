<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Icon from '@/Core/Components/Icon.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import AppStatusBadge from './AppStatusBadge.vue';
import { useAppCatalog } from './catalog';
import { useAppStoreTerms } from './terms';
import type { AppOffer, StoreApp } from './types';

const props = defineProps<{ app: StoreApp }>();

const { t } = useI18n();
const format = useFormat();
const terms = useAppStoreTerms();
const catalog = useAppCatalog();

function offerPrice(offer: AppOffer): string {
    if (offer.type === 'free') {
        return t('appStore.terms.free');
    }

    if (offer.type === 'enterprise' || offer.type === 'usage_based' || offer.amount === null || offer.currency === null) {
        return terms.billingType(offer.type);
    }

    const amount = format.money(offer.amount, offer.currency);

    return offer.type === 'monthly' || offer.type === 'yearly'
        ? t(`appStore.terms.price.${offer.type}`, { amount })
        : amount;
}
</script>

<template>
    <Card as="article" class="h-full">
        <div class="flex h-full flex-col">
            <div class="flex items-start gap-3">
                <div class="glass-well flex size-11 shrink-0 items-center justify-center rounded-[0.75rem] text-brand-text">
                    <Icon :name="app.icon ?? 'package'" class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <h2 class="text-[0.9375rem] font-semibold tracking-tight wrap-break-word text-ink">{{ catalog.name(app) }}</h2>
                        <Badge v-if="app.featured" tone="brand">{{ t('appStore.badges.featured') }}</Badge>
                        <Badge v-if="app.popular" tone="accent">{{ t('appStore.badges.popular') }}</Badge>
                    </div>
                    <p class="mt-0.5 text-xs text-ink-subtle">{{ terms.category(app.category) }}</p>
                </div>
                <AppStatusBadge v-if="app.installation" :status="app.installation.status" />
            </div>

            <p class="mt-4 line-clamp-3 text-[0.8125rem] leading-5 text-ink-muted">{{ catalog.description(app) }}</p>

            <div class="mt-auto pt-5">
                <div class="mb-4 flex flex-wrap gap-1.5" :aria-label="t('appStore.card.pricing')">
                    <Badge v-for="offer in app.offers" :key="offer.key" tone="neutral">{{ offerPrice(offer) }}</Badge>
                </div>
                <Button :href="route('core.apps.show', { app: app.key })" block>
                    {{ t('appStore.actions.viewDetails') }}
                    <template #icon><ArrowRight class="size-4" aria-hidden="true" /></template>
                </Button>
            </div>
        </div>
    </Card>
</template>

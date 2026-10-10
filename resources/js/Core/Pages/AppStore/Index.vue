<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FilterX, PackageOpen, Search, SearchX } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppCard from '@/Core/Components/AppStore/AppCard.vue';
import { useAppStoreTerms } from '@/Core/Components/AppStore/terms';
import type { AppBillingType, AppCategory, AppStoreFilters, StoreApp } from '@/Core/Components/AppStore/types';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    apps: StoreApp[];
    filters: AppStoreFilters;
    categories: AppCategory[];
    pricingTypes: AppBillingType[];
}>();

const { t } = useI18n();
const terms = useAppStoreTerms();
const form = useForm<{ q: string; category: string; pricing: string }>({
    q: props.filters.q,
    category: props.filters.category ?? '',
    pricing: props.filters.pricing ?? '',
});

const applied = computed(() => props.filters.q !== '' || props.filters.category !== null || props.filters.pricing !== null);
const anySet = computed(() => applied.value || form.q !== '' || form.category !== '' || form.pricing !== '');
const categoryOptions = computed(() => [
    { value: '', label: t('appStore.filters.allCategories') },
    ...props.categories.map((category) => ({ value: category, label: terms.category(category) })),
]);
const pricingOptions = computed(() => [
    { value: '', label: t('appStore.filters.allPricing') },
    ...props.pricingTypes.map((pricing) => ({ value: pricing, label: terms.billingType(pricing) })),
]);

function apply(): void {
    form.transform((data) => Object.fromEntries(Object.entries(data).filter(([, value]) => value.trim() !== ''))).get(route('core.apps.index'), {
        preserveState: true,
        preserveScroll: true,
    });
}

function clear(): void {
    form.q = '';
    form.category = '';
    form.pricing = '';
    form.clearErrors();

    if (applied.value) {
        apply();
    }
}
</script>

<template>
    <PageHeader :eyebrow="t('shell.nav.sections.apps')" :title="t('appStore.index.title')" :description="t('appStore.index.description')">
        <template #actions>
            <Button :href="route('core.apps.mine')">{{ t('appStore.actions.myApps') }}</Button>
        </template>
    </PageHeader>

    <Card flush>
        <form
            role="search"
            :aria-label="t('appStore.filters.label')"
            class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-[minmax(16rem,1fr)_15rem_13rem_auto]"
            novalidate
            @submit.prevent="apply"
        >
            <div class="sm:col-span-2 xl:col-span-1">
                <Input
                    v-model="form.q"
                    name="q"
                    :label="t('appStore.filters.search')"
                    :placeholder="t('appStore.filters.searchPlaceholder')"
                    :error="form.errors.q"
                    maxlength="100"
                    autocomplete="off"
                    enterkeyhint="search"
                >
                    <template #leading><Search class="size-4" aria-hidden="true" /></template>
                </Input>
            </div>
            <Select
                v-model="form.category"
                name="category"
                :label="t('appStore.filters.category')"
                :options="categoryOptions"
                :error="form.errors.category"
            />
            <Select
                v-model="form.pricing"
                name="pricing"
                :label="t('appStore.filters.pricing')"
                :options="pricingOptions"
                :error="form.errors.pricing"
            />
            <div class="flex flex-col gap-1.5">
                <span class="hidden text-[0.8125rem] font-medium xl:block" aria-hidden="true">&nbsp;</span>
                <div class="flex min-h-10 flex-wrap items-center gap-2">
                    <Button type="submit" :loading="form.processing">{{ t('appStore.filters.apply') }}</Button>
                    <Button v-if="anySet" variant="ghost" :disabled="form.processing" @click="clear">
                        <template #icon><FilterX class="size-4" aria-hidden="true" /></template>
                        {{ t('appStore.filters.clear') }}
                    </Button>
                </div>
            </div>
        </form>

        <div class="border-t border-line p-5" :aria-busy="form.processing || undefined">
            <div v-if="apps.length" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                <AppCard v-for="app in apps" :key="app.key" :app="app" />
            </div>
            <EmptyState
                v-else-if="applied"
                :title="t('appStore.empty.noMatch.title')"
                :description="t('appStore.empty.noMatch.body')"
            >
                <template #icon><SearchX /></template>
                <Button size="sm" @click="clear">{{ t('appStore.filters.clear') }}</Button>
            </EmptyState>
            <EmptyState v-else :title="t('appStore.empty.catalogue.title')" :description="t('appStore.empty.catalogue.body')">
                <template #icon><PackageOpen /></template>
            </EmptyState>
        </div>
    </Card>
</template>

<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import WebsiteNav from '../../Components/Website/WebsiteNav.vue';

defineOptions({ layout: PlatformLayout });

type Locale = 'en' | 'bn';

interface Section {
    key: string;
    kind: 'page' | 'app' | 'legal';
    name: string | null;
}

interface Field {
    key: string;
    path: string;
    default: Record<Locale, string>;
    value: Record<Locale, string | null>;
    placeholders: string[];
    long: boolean;
}

const props = defineProps<{
    sections: Section[];
    section: string;
    fields: Field[];
    /** The page on the website where this part shows. */
    preview: string;
}>();

const { t } = useI18n();
const format = useFormat();

const LOCALES: Locale[] = ['en', 'bn'];

/*
 * Every word starts as what the website shows now: the operator's word, or the website's own. Saving a word equal to
 * the website's own removes the change (the server compares), so editing in place never pins the defaults.
 */
const form = useForm({
    values: Object.fromEntries(
        LOCALES.map((locale) => [locale, Object.fromEntries(props.fields.map((field) => [field.key, field.value[locale] ?? field.default[locale]]))]),
    ) as Record<Locale, Record<string, string>>,
});

const query = ref('');
const confirmReset = ref(false);
const resetting = ref(false);

const groups = computed(() =>
    (['page', 'app', 'legal'] as const)
        .map((kind) => ({ kind, items: props.sections.filter((section) => section.kind === kind) }))
        .filter((group) => group.items.length > 0),
);

function sectionName(section: Section): string {
    return section.name ?? t(`platformWebsite.texts.sections.${section.key}`);
}

const current = computed(() => props.sections.find((section) => section.key === props.section));

function changed(field: Field, locale: Locale): boolean {
    const word = (form.values[locale][field.key] ?? '').trim();

    return word !== '' && word !== field.default[locale];
}

const changedCount = computed(() => props.fields.reduce((count, field) => count + LOCALES.filter((locale) => changed(field, locale)).length, 0));

/** "local.0.title" reads "Local › 1 › Title". */
function label(field: Field): string {
    return field.path
        .split('.')
        .map((part) => (/^\d+$/.test(part) ? String(Number(part) + 1) : part.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase())))
        .join(' › ');
}

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.fields;
    }

    return props.fields.filter((field) =>
        [field.path, field.default.en, field.default.bn, form.values.en[field.key], form.values.bn[field.key]].some((text) => (text ?? '').toLowerCase().includes(needle)),
    );
});

function error(field: Field, locale: Locale): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`${locale}:${field.key}`];
}

function save(): void {
    form.put(route('platform.website.texts.update', { section: props.section }), { preserveScroll: true });
}

function reset(): void {
    resetting.value = true;
    router.delete(route('platform.website.texts.reset', { section: props.section }), {
        preserveScroll: true,
        onFinish: () => {
            resetting.value = false;
            confirmReset.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('platformWebsite.eyebrow')" :title="t('platformWebsite.texts.title')" :description="t('platformWebsite.texts.description')" />

    <WebsiteNav current="texts" />

    <div class="grid gap-3 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <!-- Every part of the website, by kind. -->
        <nav :aria-label="t('platformWebsite.texts.part')" class="lg:sticky lg:top-24 lg:self-start">
            <Card>
                <div class="grid gap-4">
                    <div v-for="group in groups" :key="group.kind">
                        <p class="eyebrow px-2">{{ t(`platformWebsite.texts.groups.${group.kind}`) }}</p>
                        <ul class="mt-1.5 grid gap-0.5">
                            <li v-for="item in group.items" :key="item.key">
                                <Link
                                    :href="route('platform.website.texts.index', { section: item.key })"
                                    preserve-scroll
                                    :aria-current="item.key === section ? 'page' : undefined"
                                    :class="[
                                        'block rounded-control px-2 py-1.5 text-[0.8125rem] wrap-break-word transition-colors',
                                        item.key === section ? 'bg-active font-medium text-ink shadow-sm' : 'text-ink-muted hover:bg-hover hover:text-ink',
                                    ]"
                                >
                                    {{ sectionName(item) }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </Card>
        </nav>

        <form novalidate @submit.prevent="save">
            <Card>
                <template #header>
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-semibold wrap-break-word text-ink">{{ current ? sectionName(current) : section }}</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">
                            {{ t('platformWebsite.texts.changedCount', { count: format.number(changedCount) }) }}
                        </p>
                    </div>
                </template>
                <template #actions>
                    <Button size="sm" :href="preview" native target="_blank" rel="noopener">{{ t('platformWebsite.texts.preview') }}</Button>
                </template>

                <Input v-model="query" type="search" :label="t('platformWebsite.texts.filter')" :placeholder="t('platformWebsite.texts.filterPlaceholder')" class="mb-5 sm:max-w-sm" />

                <p v-if="visible.length === 0" class="py-6 text-center text-sm text-ink-muted">{{ t('platformWebsite.texts.noMatch') }}</p>

                <ul class="divide-y divide-line" data-testid="words">
                    <li v-for="field in visible" :key="field.key" class="py-4 first:pt-0 last:pb-0" :data-key="field.key">
                        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="font-mono text-xs text-ink-subtle">{{ label(field) }}</span>
                            <Badge v-if="changed(field, 'en') || changed(field, 'bn')" tone="brand">{{ t('platformWebsite.texts.changed') }}</Badge>
                            <span v-if="field.placeholders.length > 0" class="text-xs text-ink-subtle">
                                {{ t('platformWebsite.texts.keep', { list: field.placeholders.join(', ') }) }}
                            </span>
                        </div>
                        <div class="@container">
                            <div class="grid gap-3 @2xl:grid-cols-2">
                                <template v-for="locale in LOCALES" :key="locale">
                                    <Textarea
                                        v-if="field.long"
                                        v-model="form.values[locale][field.key]"
                                        :lang="locale"
                                        :rows="3"
                                        :label="locale === 'en' ? t('platformWebsite.texts.english') : t('platformWebsite.texts.bengali')"
                                        :hint="changed(field, locale) ? `${t('platformWebsite.texts.own')}: ${field.default[locale]}` : undefined"
                                        :error="error(field, locale)"
                                        maxlength="5000"
                                    />
                                    <Input
                                        v-else
                                        v-model="form.values[locale][field.key]"
                                        :lang="locale"
                                        :label="locale === 'en' ? t('platformWebsite.texts.english') : t('platformWebsite.texts.bengali')"
                                        :hint="changed(field, locale) ? `${t('platformWebsite.texts.own')}: ${field.default[locale]}` : undefined"
                                        :error="error(field, locale)"
                                        maxlength="5000"
                                    />
                                </template>
                            </div>
                        </div>
                    </li>
                </ul>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <Button variant="ghost" @click="confirmReset = true">{{ t('platformWebsite.texts.reset') }}</Button>
                        <Button type="submit" variant="primary" :loading="form.processing">{{ t('platformWebsite.texts.save') }}</Button>
                    </div>
                </template>
            </Card>
        </form>
    </div>

    <ConfirmDialog
        v-model:open="confirmReset"
        :title="t('platformWebsite.texts.resetTitle')"
        :description="t('platformWebsite.texts.resetBody')"
        :confirm-label="t('platformWebsite.texts.reset')"
        :loading="resetting"
        @confirm="reset"
    />
</template>

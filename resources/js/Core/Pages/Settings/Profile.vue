<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import Select, { type SelectOption } from '@/Core/Components/Select.vue';
import SettingsTabs from '@/Core/Components/Settings/SettingsTabs.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { formatDate, formatNumber, type FormatContext } from '@/Core/lib/format';
import { route } from '@/Core/lib/route';
import type { Numerals } from '@/Core/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    profile: {
        name: string;
        email: string;
        /** Interface language code, one of the keys of `app.locales`. */
        locale: string;
        numerals: Numerals;
    };
}>();

const page = usePage();
const { t } = useI18n();

const form = useForm({
    name: props.profile.name,
    locale: props.profile.locale,
    numerals: props.profile.numerals,
});

const languages = computed<SelectOption[]>(() =>
    Object.entries(page.props.app.locales).map(([value, label]) => ({ value, label })),
);

const numeralOptions = computed<Segment<Numerals>[]>(() => [
    { value: 'latn', label: t('settings.profile.numerals.latn') },
    { value: 'beng', label: t('settings.profile.numerals.beng') },
]);

/*
 * The preview formats with what is selected in the form, not with what is
 * saved - which is all `useFormat()` knows about.
 */
const previewContext = computed<FormatContext>(() => ({
    locale: form.locale,
    numerals: form.numerals,
    timezone: page.props.app.timezone,
    currency: page.props.app.currency,
}));

const SAMPLE_NUMBER = 1234567.89;
const now = new Date();

const preview = computed(() => ({
    number: formatNumber(SAMPLE_NUMBER, previewContext.value),
    date: formatDate(now, previewContext.value, 'date'),
    time: formatDate(now, previewContext.value, 'time'),
}));

function submit(): void {
    form.put(route('core.settings.profile.update'), { preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader
            :eyebrow="t('settings.eyebrow')"
            :title="t('settings.profile.title')"
            :description="t('settings.profile.description')"
        >
            <template #actions><SettingsTabs current="profile" /></template>
        </PageHeader>

        <!-- One form, one save: the server takes name, language and numerals together. Full width like the platform
             Settings page: one box per part, side by side on wide screens. Each box lays out its own fields by its own
             width (container queries), not the screen's. -->
        <form novalidate class="grid gap-3 lg:grid-cols-2" @submit.prevent="submit">
            <Card :title="t('settings.profile.about.title')" :description="t('settings.profile.about.description')">
                <div class="@container">
                    <div class="grid content-start gap-4 @lg:grid-cols-2">
                        <Input
                            v-model="form.name"
                            name="name"
                            :label="t('settings.profile.name')"
                            :error="form.errors.name"
                            autocomplete="name"
                            maxlength="120"
                            required
                        />

                        <Input
                            :model-value="profile.email"
                            type="email"
                            name="email"
                            :label="t('settings.profile.email')"
                            :hint="t('settings.profile.emailHint')"
                            control-class="text-ink-muted"
                            autocomplete="email"
                            readonly
                        >
                            <template #trailing>
                                <Lock class="mr-2 size-3.5 text-ink-subtle" aria-hidden="true" />
                            </template>
                        </Input>
                    </div>
                </div>
            </Card>

            <Card :title="t('settings.profile.regional.title')" :description="t('settings.profile.regional.description')">
                <div class="@container flex flex-col gap-4">
                    <div class="grid gap-4 @lg:grid-cols-2">
                        <Select
                            v-model="form.locale"
                            name="locale"
                            :options="languages"
                            :label="t('settings.profile.language')"
                            :error="form.errors.locale"
                        />

                        <Field :label="t('settings.profile.numerals.label')" :error="form.errors.numerals">
                            <!-- Centred on the height of the select beside it. -->
                            <div class="flex min-h-10 items-center">
                                <SegmentedControl
                                    v-model="form.numerals"
                                    :options="numeralOptions"
                                    :label="t('settings.profile.numerals.label')"
                                />
                            </div>
                        </Field>
                    </div>

                    <!-- Follows the two controls above as they change, before anything is saved. -->
                    <div class="glass-well rounded-control px-4 py-3.5">
                        <p class="eyebrow">{{ t('settings.profile.preview.title') }}</p>

                        <dl class="mt-3 grid gap-x-8 gap-y-4 @lg:grid-cols-2" aria-live="polite">
                            <div>
                                <dt class="text-[0.8125rem] text-ink-subtle">{{ t('settings.profile.preview.number') }}</dt>
                                <dd class="figure mt-1.5 text-[1.625rem] text-ink [&:lang(bn)]:leading-[1.3]" :lang="form.locale">
                                    {{ preview.number }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[0.8125rem] text-ink-subtle">{{ t('settings.profile.preview.date') }}</dt>
                                <dd class="mt-1.5" :lang="form.locale">
                                    <span class="figure block text-[1.625rem] text-ink [&:lang(bn)]:leading-[1.3]">{{ preview.date }}</span>
                                    <span class="tabular mt-1 block text-[0.8125rem] text-ink-muted">{{ preview.time }}</span>
                                </dd>
                            </div>
                        </dl>

                        <p class="mt-3 border-t border-line pt-3 text-[0.8125rem] text-ink-subtle">
                            {{ t('settings.profile.preview.note') }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- One save for both boxes, across the row beneath them. -->
            <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 px-1 lg:col-span-2">
                <p v-if="form.isDirty" class="mr-auto text-[0.8125rem] text-ink-muted">{{ t('settings.profile.unsaved') }}</p>
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="!form.isDirty">
                    {{ t('common.actions.save') }}
                </Button>
            </div>
        </form>
    </div>
</template>

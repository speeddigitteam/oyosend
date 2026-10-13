<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import SocialFormDialog from './SocialFormDialog.vue';
import { requestMappingPreview, type LeadFormRow, type MappingChoice, type MappingPreview, type MappingTarget } from './leadAds';

/**
 * Maps a lead form's questions to the lead fields. Every change is previewed by the server with an example
 * lead (the same code that processes real leads), so what is shown is what will happen.
 */
const props = defineProps<{ open: boolean; form: LeadFormRow | null; targets: MappingTarget[] }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();

const draft = ref<Record<string, MappingChoice>>({});
const preview = ref<MappingPreview | null>(null);
const warnings = ref<string[]>([]);
const processing = ref(false);
const errors = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let controller: AbortController | null = null;

const model = computed({ get: () => props.open, set: (value: boolean) => emit('update:open', value) });

watch(
    () => [props.open, props.form?.id] as const,
    () => {
        if (props.open && props.form) {
            draft.value = JSON.parse(JSON.stringify(props.form.mapping)) as Record<string, MappingChoice>;
            preview.value = props.form.preview;
            warnings.value = props.form.warnings;
            errors.value = null;
        }
    },
    { immediate: true },
);

const targetOptions = computed(() => props.targets.map((value) => ({ value, label: t(`leadAds.mapping.targets.${value}`) })));

function payload(): Record<string, { target: MappingTarget; answer_key: string | null }> {
    const out: Record<string, { target: MappingTarget; answer_key: string | null }> = {};

    for (const [key, choice] of Object.entries(draft.value)) {
        out[key] = { target: choice.target, answer_key: choice.target === 'answer' && choice.answerKey ? choice.answerKey : null };
    }

    return out;
}

function schedule(): void {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(async () => {
        if (!props.form) {
            return;
        }

        controller?.abort();
        controller = new AbortController();
        const result = await requestMappingPreview(route('social.leadads.forms.preview', { form: props.form.id }), payload(), controller.signal);

        if (result) {
            preview.value = result.preview;
            warnings.value = result.warnings;
        }
    }, 250);
}

watch(draft, schedule, { deep: true });

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
});

function submit(): void {
    if (!props.form) {
        return;
    }

    processing.value = true;
    errors.value = null;
    router.put(route('social.leadads.forms.mapping', { form: props.form.id }), { mapping: payload() }, {
        preserveScroll: true,
        onSuccess: () => {
            model.value = false;
        },
        onError: (bag) => {
            errors.value = typeof bag.mapping === 'string' ? bag.mapping : t('leadAds.mapping.saveFailed');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const answerEntries = computed(() => Object.entries(preview.value?.answers ?? {}));
const consentCount = computed(() => Object.values(draft.value).filter((choice) => choice.target === 'consent').length);
</script>

<template>
    <SocialFormDialog
        v-model:open="model"
        size="lg"
        :title="t('leadAds.mapping.title', { name: form?.name ?? '' })"
        :description="t('leadAds.mapping.description')"
        :submit-label="t('leadAds.mapping.save')"
        :processing="processing"
        :disabled="consentCount > 1"
        @submit="submit"
    >
        <div v-if="form" class="grid gap-4" data-testid="mapping-dialog">
            <ul class="grid gap-3">
                <li
                    v-for="question in form.questions"
                    :key="question.key"
                    class="grid gap-2 rounded-card border border-line p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)]"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium break-words text-ink">{{ question.label }}</p>
                        <p class="font-mono text-xs break-all text-ink-subtle">{{ question.key }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Select
                            v-if="draft[question.key]"
                            v-model="draft[question.key]!.target"
                            :options="targetOptions"
                            :label="t('leadAds.mapping.goesTo')"
                            :data-testid="`target-${question.key}`"
                        />
                        <Input
                            v-if="draft[question.key]?.target === 'answer'"
                            :model-value="draft[question.key]!.answerKey ?? ''"
                            :label="t('leadAds.mapping.answerKey')"
                            :placeholder="question.key"
                            maxlength="64"
                            @update:model-value="draft[question.key]!.answerKey = String($event)"
                        />
                    </div>
                </li>
            </ul>

            <p v-if="consentCount > 1" class="text-[0.8125rem] text-danger" role="alert">{{ t('leadAds.mapping.severalConsent') }}</p>
            <p class="text-xs text-ink-subtle">{{ t('leadAds.mapping.consentHint') }}</p>

            <section class="glass-well rounded-card p-4" aria-live="polite" data-testid="mapping-preview">
                <h3 class="text-sm font-semibold text-ink">{{ t('leadAds.mapping.preview') }}</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">{{ t('leadAds.mapping.previewNote') }}</p>

                <dl v-if="preview" class="mt-3 grid gap-x-4 gap-y-2 text-[0.8125rem] sm:grid-cols-2">
                    <div v-for="field in (['name', 'email', 'phone', 'company'] as const)" :key="field">
                        <dt class="eyebrow">{{ t(`leadAds.mapping.targets.${field}`) }}</dt>
                        <dd class="break-words text-ink">{{ preview[field] ?? t('leadAds.common.none') }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="eyebrow">{{ t('leadAds.mapping.answers') }}</dt>
                        <dd v-if="answerEntries.length === 0" class="text-ink-muted">{{ t('leadAds.common.none') }}</dd>
                        <dd v-else>
                            <ul class="grid gap-0.5">
                                <li v-for="[key, value] in answerEntries" :key="key" class="break-words text-ink-muted">
                                    <span class="text-ink">{{ key }}</span>: {{ value }}
                                </li>
                            </ul>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="eyebrow">{{ t('leadAds.mapping.consentEvidence') }}</dt>
                        <dd class="text-ink-muted">{{ preview.consentEvidence ? t('leadAds.mapping.consentYes') : t('leadAds.mapping.consentNo') }}</dd>
                    </div>
                </dl>

                <p v-if="preview?.problem" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ t(`leadAds.mapping.problems.${preview.problem}`) }}</p>
                <ul v-if="warnings.length > 0" class="mt-3 grid gap-1 text-[0.8125rem] text-warn">
                    <li v-for="warning in warnings" :key="warning">{{ t(`leadAds.mapping.warnings.${warning}`) }}</li>
                </ul>
            </section>

            <p v-if="errors" class="text-[0.8125rem] text-danger" role="alert">{{ errors }}</p>
        </div>
    </SocialFormDialog>
</template>

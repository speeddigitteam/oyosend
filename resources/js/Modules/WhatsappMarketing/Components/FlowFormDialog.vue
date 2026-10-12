<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import FlowStepEditor from './FlowStepEditor.vue';
import type { AgentOption, FlowLimits, FlowRow } from './types';
import { addKeyword, buildFlowPayload, emptyFlowForm, type FlowForm, flowFormValid, flowToForm, newStep } from './whatsappFormat';

/**
 * Builds a chatbot flow declaratively: a trigger, then an ordered list of steps from a closed vocabulary and
 * at most one menu at the end. It never accepts code or expressions; the server validates every field again.
 */
const props = defineProps<{
    flow: FlowRow | null;
    agents: AgentOption[];
    limits: FlowLimits;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useFormat();

const state = ref<FlowForm>(emptyFlowForm());
const keywordDraft = ref('');
const processing = ref(false);
const form = useForm({});

watch(
    () => [open.value, props.flow?.id] as const,
    ([isOpen]) => {
        if (isOpen) {
            state.value = props.flow ? flowToForm(props.flow) : emptyFlowForm();
            keywordDraft.value = '';
            form.clearErrors();
        }
    },
    { immediate: true },
);

const errors = computed(() => form.errors as Record<string, string | undefined>);
const matchOptions = computed(() => [
    { value: 'contains', label: t('whatsapp.flows.matchContains') },
    { value: 'exact', label: t('whatsapp.flows.matchExact') },
]);
const triggerOptions = computed(() => [
    { value: 'keyword', label: t('whatsapp.flows.triggerKeyword') },
    { value: 'welcome', label: t('whatsapp.flows.triggerWelcome') },
]);

// One slot of the step limit belongs to the menu when there is one.
const stepMax = computed(() => (state.value.menu === null ? props.limits.actions : Math.max(1, props.limits.actions - 1)));
const valid = computed(() => flowFormValid(state.value));

function addKeywordFromDraft(): void {
    state.value.keywords = addKeyword(state.value.keywords, keywordDraft.value, props.limits.keywords);
    keywordDraft.value = '';
}

function addMenu(): void {
    state.value.menu = { prompt: '', options: [{ label: '', actions: [newStep('reply')] }] };
}

function addOption(): void {
    if (state.value.menu && state.value.menu.options.length < props.limits.options) {
        state.value.menu.options.push({ label: '', actions: [newStep('reply')] });
    }
}

function submit(): void {
    const payload = buildFlowPayload(state.value, props.limits);
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    };

    form.transform(() => payload);

    if (props.flow) {
        form.put(route('whatsapp_marketing.flows.update', { flow: props.flow.id }), options);
    } else {
        form.post(route('whatsapp_marketing.flows.store'), options);
    }
}

function setPriority(value: string | number | null | undefined): void {
    const number = Number(value);

    if (Number.isFinite(number) && number >= 1) {
        state.value.priority = Math.min(1000, Math.trunc(number));
    }
}

function optionError(index: number, field: string): string | undefined {
    const menuIndex = state.value.steps.length;

    return errors.value[`actions.${menuIndex}.options.${index}.${field}`];
}
</script>

<template>
    <Modal
        v-model:open="open"
        size="lg"
        :title="flow ? t('whatsapp.flows.editTitle') : t('whatsapp.flows.createTitle')"
        :description="t('whatsapp.flows.formDescription')"
        :persistent="processing"
    >
        <form id="whatsapp-flow" class="grid gap-5" novalidate @submit.prevent="submit">
            <Input v-model="state.name" :label="t('whatsapp.flows.name')" :error="errors.name" maxlength="120" />

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('whatsapp.flows.when') }}</legend>
                <SegmentedControl v-model="state.trigger" :options="triggerOptions" :label="t('whatsapp.flows.trigger')" />
                <p v-if="errors.trigger" role="alert" class="text-sm text-danger">{{ errors.trigger }}</p>
                <p class="text-sm text-ink-muted">{{ state.trigger === 'welcome' ? t('whatsapp.flows.welcomeHint') : t('whatsapp.flows.keywordHint') }}</p>

                <template v-if="state.trigger === 'keyword'">
                    <Select v-model="state.match_type" :options="matchOptions" :label="t('whatsapp.flows.match')" :error="errors.match_type" />
                    <div class="grid gap-2">
                        <div class="flex items-end gap-2">
                            <Input
                                v-model="keywordDraft"
                                class="min-w-0 flex-1"
                                :label="t('whatsapp.flows.keywords')"
                                :hint="t('whatsapp.flows.keywordsHint', { max: format.number(limits.keywords) })"
                                :error="errors.keywords"
                                maxlength="40"
                                @keydown.enter.prevent="addKeywordFromDraft"
                            />
                            <Button size="sm" variant="secondary" :disabled="keywordDraft.trim() === '' || state.keywords.length >= limits.keywords" @click="addKeywordFromDraft">
                                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                                {{ t('whatsapp.flows.addKeyword') }}
                            </Button>
                        </div>
                        <ul v-if="state.keywords.length" class="flex flex-wrap gap-1.5" :aria-label="t('whatsapp.flows.keywords')">
                            <li v-for="keyword in state.keywords" :key="keyword">
                                <Badge tone="info">
                                    <span class="wrap-anywhere">{{ keyword }}</span>
                                    <button
                                        type="button"
                                        class="-mr-1 ml-1 inline-flex rounded-chip p-0.5 hover:bg-hover focus-visible:outline-2"
                                        :aria-label="t('whatsapp.flows.removeKeyword', { keyword })"
                                        @click="state.keywords = state.keywords.filter((item) => item !== keyword)"
                                    >
                                        <X class="size-3" aria-hidden="true" />
                                    </button>
                                </Badge>
                            </li>
                        </ul>
                    </div>
                </template>
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('whatsapp.flows.then') }}</legend>
                <p v-if="errors.actions" role="alert" class="text-sm text-danger">{{ errors.actions }}</p>
                <FlowStepEditor v-model="state.steps" :max="stepMax" :agents="agents" :label="t('whatsapp.flows.then')" :errors="errors" />
            </fieldset>

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('whatsapp.flows.menu') }}</legend>
                <template v-if="state.menu === null">
                    <p class="text-sm text-ink-muted">{{ t('whatsapp.flows.menuHint') }}</p>
                    <div>
                        <Button size="sm" variant="secondary" @click="addMenu">
                            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.flows.addMenu') }}
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <Textarea
                        v-model="state.menu.prompt"
                        :label="t('whatsapp.flows.menuPrompt')"
                        :hint="t('whatsapp.flows.menuPromptHint')"
                        :error="errors[`actions.${state.steps.length}.prompt`]"
                        :rows="2"
                        maxlength="500"
                    />
                    <p v-if="errors[`actions.${state.steps.length}.options`]" role="alert" class="text-sm text-danger">{{ errors[`actions.${state.steps.length}.options`] }}</p>
                    <div v-for="(option, index) in state.menu.options" :key="index" class="grid gap-3 rounded-control border border-line p-3" data-testid="menu-option">
                        <div class="flex items-end gap-2">
                            <Input
                                v-model="option.label"
                                class="min-w-0 flex-1"
                                :label="t('whatsapp.flows.optionLabel', { number: format.number(index + 1) })"
                                :error="optionError(index, 'label')"
                                maxlength="40"
                            />
                            <Button v-if="state.menu.options.length > 1" size="sm" variant="ghost" icon @click="state.menu.options.splice(index, 1)">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                                {{ t('whatsapp.flows.removeOption') }}
                            </Button>
                        </div>
                        <p v-if="optionError(index, 'actions')" role="alert" class="text-sm text-danger">{{ optionError(index, 'actions') }}</p>
                        <FlowStepEditor
                            v-model="option.actions"
                            :max="limits.optionActions"
                            :agents="agents"
                            :label="t('whatsapp.flows.optionSteps', { number: format.number(index + 1) })"
                            :errors="errors"
                            :error-prefix="`actions.${state.steps.length}.options.${index}.actions`"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="state.menu.options.length < limits.options" size="sm" variant="secondary" @click="addOption">
                            <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.flows.addOption') }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="state.menu = null">{{ t('whatsapp.flows.removeMenu') }}</Button>
                    </div>
                </template>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2">
                <Input :model-value="state.priority" type="number" min="1" max="1000" @update:model-value="setPriority" :label="t('whatsapp.flows.priority')" :hint="t('whatsapp.flows.priorityHint')" :error="errors.priority" />
                <Checkbox v-model="state.is_active" :label="t('whatsapp.flows.isActive')" :description="t('whatsapp.flows.isActiveHint')" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="processing" @click="open = false">{{ t('whatsapp.common.cancel') }}</Button>
            <Button type="submit" form="whatsapp-flow" variant="primary" :loading="processing" :disabled="!valid">{{ t('whatsapp.common.save') }}</Button>
        </template>
    </Modal>
</template>

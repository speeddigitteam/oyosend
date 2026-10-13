<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { CHOICE_LIMITS, type ChoicesForm } from './whatsappFormat';

/**
 * Reply buttons or a list for a free-form reply. Only labels are entered; the customer's tap comes back as the
 * label's text. Limits mirror Meta's and the server checks them again.
 */
const props = defineProps<{ errors?: Record<string, string | undefined> }>();

const choices = defineModel<ChoicesForm>({ required: true });

const { t } = useI18n();
const format = useFormat();

const kindOptions = computed(() => [
    { value: '' as const, label: t('whatsapp.composer.choicesNone') },
    { value: 'buttons' as const, label: t('whatsapp.composer.choicesButtons') },
    { value: 'list' as const, label: t('whatsapp.composer.choicesList') },
]);

const kind = computed({
    get: () => choices.value.kind,
    set: (value: ChoicesForm['kind']) => {
        const max = value === 'list' ? CHOICE_LIMITS.rows : CHOICE_LIMITS.buttons;
        choices.value = { ...choices.value, kind: value, options: choices.value.options.slice(0, max) };
    },
});
const max = computed(() => (choices.value.kind === 'list' ? CHOICE_LIMITS.rows : CHOICE_LIMITS.buttons));
const length = computed(() => (choices.value.kind === 'list' ? CHOICE_LIMITS.rowLength : CHOICE_LIMITS.buttonLength));

function setOption(index: number, value: string | number | null | undefined): void {
    const options = [...choices.value.options];
    options[index] = String(value ?? '');
    choices.value = { ...choices.value, options };
}

function addOption(): void {
    if (choices.value.options.length < max.value) {
        choices.value = { ...choices.value, options: [...choices.value.options, ''] };
    }
}

function removeOption(index: number): void {
    choices.value = { ...choices.value, options: choices.value.options.filter((_, position) => position !== index) };
}

function error(key: string): string | undefined {
    return props.errors?.[key];
}
</script>

<template>
    <div class="grid gap-3" data-testid="choices-editor">
        <SegmentedControl v-model="kind" :options="kindOptions" :label="t('whatsapp.composer.choices')" />

        <template v-if="choices.kind !== ''">
            <p class="text-xs text-ink-subtle">
                {{
                    t('whatsapp.composer.choicesHint', {
                        buttons: format.number(CHOICE_LIMITS.buttons),
                        buttonLength: format.number(CHOICE_LIMITS.buttonLength),
                        rows: format.number(CHOICE_LIMITS.rows),
                        rowLength: format.number(CHOICE_LIMITS.rowLength),
                    })
                }}
            </p>
            <Input
                v-if="choices.kind === 'list'"
                :model-value="choices.button"
                :label="t('whatsapp.composer.listButton')"
                :hint="t('whatsapp.composer.listButtonHint', { max: format.number(CHOICE_LIMITS.listButton) })"
                :error="error('interactive.button')"
                :maxlength="CHOICE_LIMITS.listButton"
                @update:model-value="choices = { ...choices, button: String($event ?? '') }"
            />
            <ol class="grid gap-2">
                <li v-for="(option, index) in choices.options" :key="index" class="flex items-end gap-2" data-testid="choice-option">
                    <Input
                        class="flex-1"
                        :model-value="option"
                        :label="t('whatsapp.composer.option', { number: format.number(index + 1) })"
                        :error="error(`interactive.options.${index}`)"
                        :maxlength="length"
                        @update:model-value="setOption(index, $event)"
                    />
                    <Button size="md" variant="ghost" icon :disabled="choices.options.length <= 1" @click="removeOption(index)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.composer.removeOption', { number: format.number(index + 1) }) }}
                    </Button>
                </li>
            </ol>
            <p v-if="error('interactive.options')" class="text-xs text-danger" role="alert">{{ error('interactive.options') }}</p>
            <div v-if="choices.options.length < max">
                <Button size="sm" variant="secondary" @click="addOption">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.composer.addOption') }}
                </Button>
            </div>
        </template>
    </div>
</template>

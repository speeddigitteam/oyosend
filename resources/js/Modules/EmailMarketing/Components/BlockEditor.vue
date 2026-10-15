<script setup lang="ts">
import { ArrowDown, ArrowUp, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { BLOCK_TYPES, isSafeLink, moveItem, newBlock, variableToken } from './emailFormat';
import type { Block, BlockType } from './types';

/**
 * The structured email editor. A message is a list of typed blocks with a fixed set of fields; there is no HTML
 * to type. Text accepts **bold**, *italic*, [label](https://link) and the personalisation variables of the list
 * the server allows. The server validates everything again and renders both the HTML and the plain-text version.
 */
const props = withDefaults(
    defineProps<{
        variables: string[];
        /** Field errors from the server, keyed `{prefix}.{index}.{field}`. */
        errors?: Record<string, string | undefined>;
        /** The prefix the server uses for this editor's errors: `blocks`, or `steps.0.blocks`. */
        errorPrefix?: string;
        max?: number;
        maxText?: number;
        disabled?: boolean;
    }>(),
    { errors: () => ({}), errorPrefix: 'blocks', max: 50, maxText: 5000, disabled: false },
);

const blocks = defineModel<Block[]>({ required: true });

const { t } = useI18n();

const alignOptions = [
    { value: 'left', label: t('emailMarketing.editor.alignLeft') },
    { value: 'center', label: t('emailMarketing.editor.alignCenter') },
];

function add(type: BlockType): void {
    if (blocks.value.length >= props.max) {
        return;
    }

    blocks.value = [...blocks.value, newBlock(type)];
}

function remove(index: number): void {
    blocks.value = blocks.value.filter((_, position) => position !== index);
}

function move(index: number, offset: number): void {
    blocks.value = moveItem(blocks.value, index, offset);
}

function patch(index: number, change: Partial<Block>): void {
    blocks.value = blocks.value.map((block, position) => (position === index ? ({ ...block, ...change } as Block) : block));
}

function insertVariable(index: number, field: 'text' | 'label', name: string): void {
    if (name === '') {
        return;
    }

    const block = blocks.value[index];
    const current = block && field in block ? String((block as unknown as Record<string, unknown>)[field] ?? '') : '';
    patch(index, { [field]: `${current}${current === '' || current.endsWith(' ') ? '' : ' '}${variableToken(name)}` } as Partial<Block>);
}

type ImageBlock = Extract<Block, { type: 'image' }>;
type ButtonBlock = Extract<Block, { type: 'button' }>;
type SpacerBlock = Extract<Block, { type: 'spacer' }>;

const asImage = (block: Block): ImageBlock => block as ImageBlock;
const asButton = (block: Block): ButtonBlock => block as ButtonBlock;
const asSpacer = (block: Block): SpacerBlock => block as SpacerBlock;
const isHttps = (url: string): boolean => /^https:/i.test(url.trim());

function fieldError(index: number, field: string): string | undefined {
    return props.errors[`${props.errorPrefix}.${index}.${field}`];
}

function linkInvalid(value: string | null, allowMailto = false): boolean {
    return value !== null && value.trim() !== '' && !isSafeLink(value, allowMailto);
}

const variableOptions = (): { value: string; label: string }[] => [
    { value: '', label: t('emailMarketing.editor.insertVariable') },
    ...props.variables.map((name) => ({ value: name, label: variableToken(name) })),
];
</script>

<template>
    <div>
        <p v-if="errors[errorPrefix]" class="mb-3 text-sm text-danger" role="alert">{{ errors[errorPrefix] }}</p>

        <ol class="grid gap-3" data-testid="blocks">
            <li
                v-for="(block, index) in blocks"
                :key="index"
                class="@container glass-well rounded-card p-4"
                :aria-label="t('emailMarketing.editor.blockLabel', { number: index + 1, type: t(`emailMarketing.editor.types.${block.type}`) })"
            >
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="eyebrow">{{ index + 1 }}. {{ t(`emailMarketing.editor.types.${block.type}`) }}</span>
                    <span class="ml-auto flex items-center gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            icon
                            :disabled="disabled || index === 0"
                            :aria-label="t('emailMarketing.editor.moveUp')"
                            @click="move(index, -1)"
                        >
                            <template #icon><ArrowUp class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            icon
                            :disabled="disabled || index === blocks.length - 1"
                            :aria-label="t('emailMarketing.editor.moveDown')"
                            @click="move(index, 1)"
                        >
                            <template #icon><ArrowDown class="size-4" aria-hidden="true" /></template>
                        </Button>
                        <Button size="sm" variant="ghost" icon :disabled="disabled" :aria-label="t('emailMarketing.editor.remove')" @click="remove(index)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        </Button>
                    </span>
                </div>

                <!-- Input passes its class to the inner control, so a full-row field gets a wrapper that spans. -->
                <div v-if="block.type === 'heading'" class="grid gap-3 @lg:grid-cols-3">
                    <div class="@lg:col-span-3">
                        <Input
                            :model-value="block.text"
                            :label="t('emailMarketing.editor.headingText')"
                            maxlength="160"
                            :disabled="disabled"
                            :error="fieldError(index, 'text')"
                            @update:model-value="patch(index, { text: String($event ?? '') })"
                        />
                    </div>
                    <Select
                        :model-value="String(block.level)"
                        :label="t('emailMarketing.editor.level')"
                        :options="[
                            { value: '1', label: t('emailMarketing.editor.level1') },
                            { value: '2', label: t('emailMarketing.editor.level2') },
                            { value: '3', label: t('emailMarketing.editor.level3') },
                        ]"
                        :disabled="disabled"
                        @update:model-value="patch(index, { level: Number($event) as 1 | 2 | 3 })"
                    />
                    <Select
                        :model-value="block.align"
                        :label="t('emailMarketing.editor.align')"
                        :options="alignOptions"
                        :disabled="disabled"
                        @update:model-value="patch(index, { align: String($event) as 'left' | 'center' })"
                    />
                    <Select
                        model-value=""
                        :label="t('emailMarketing.editor.variable')"
                        :options="variableOptions()"
                        :disabled="disabled"
                        @update:model-value="insertVariable(index, 'text', String($event ?? ''))"
                    />
                </div>

                <div v-else-if="block.type === 'text'" class="grid gap-3 @md:grid-cols-2">
                    <div class="@md:col-span-2">
                        <Textarea
                            :model-value="block.text"
                            :label="t('emailMarketing.editor.textBody')"
                            :hint="t('emailMarketing.editor.textHint')"
                            :rows="5"
                            :maxlength="maxText"
                            :disabled="disabled"
                            :error="fieldError(index, 'text')"
                            @update:model-value="patch(index, { text: String($event ?? '') })"
                        />
                    </div>
                    <Select
                        :model-value="block.align"
                        :label="t('emailMarketing.editor.align')"
                        :options="alignOptions"
                        :disabled="disabled"
                        @update:model-value="patch(index, { align: String($event) as 'left' | 'center' })"
                    />
                    <Select
                        model-value=""
                        :label="t('emailMarketing.editor.variable')"
                        :options="variableOptions()"
                        :disabled="disabled"
                        @update:model-value="insertVariable(index, 'text', String($event ?? ''))"
                    />
                </div>

                <div v-else-if="block.type === 'image'" class="grid gap-3 @md:grid-cols-2">
                    <div class="@md:col-span-2">
                        <Input
                            type="url"
                            inputmode="url"
                            :model-value="asImage(block).url"
                            :label="t('emailMarketing.editor.imageUrl')"
                            :hint="t('emailMarketing.editor.imageHint')"
                            :disabled="disabled"
                            :error="fieldError(index, 'url') ?? (asImage(block).url !== '' && !isHttps(asImage(block).url) ? t('emailMarketing.editor.httpsOnly') : undefined)"
                            @update:model-value="patch(index, { url: String($event ?? '') })"
                        />
                    </div>
                    <Input
                        :model-value="asImage(block).alt"
                        :label="t('emailMarketing.editor.imageAlt')"
                        maxlength="200"
                        :disabled="disabled"
                        :error="fieldError(index, 'alt')"
                        @update:model-value="patch(index, { alt: String($event ?? '') })"
                    />
                    <Input
                        type="url"
                        inputmode="url"
                        :model-value="asImage(block).link_url ?? ''"
                        :label="t('emailMarketing.editor.imageLink')"
                        optional
                        :disabled="disabled"
                        :error="fieldError(index, 'link_url') ?? (linkInvalid(asImage(block).link_url) ? t('emailMarketing.editor.linkInvalid') : undefined)"
                        @update:model-value="patch(index, { link_url: String($event ?? '') === '' ? null : String($event) })"
                    />
                </div>

                <div v-else-if="block.type === 'button'" class="grid gap-3 @md:grid-cols-2">
                    <Input
                        :model-value="asButton(block).label"
                        :label="t('emailMarketing.editor.buttonLabel')"
                        maxlength="60"
                        :disabled="disabled"
                        :error="fieldError(index, 'label')"
                        @update:model-value="patch(index, { label: String($event ?? '') })"
                    />
                    <Input
                        type="text"
                        inputmode="url"
                        :model-value="asButton(block).url"
                        :label="t('emailMarketing.editor.buttonUrl')"
                        :disabled="disabled"
                        :error="fieldError(index, 'url') ?? (asButton(block).url !== '' && !isSafeLink(asButton(block).url, true) ? t('emailMarketing.editor.linkInvalid') : undefined)"
                        @update:model-value="patch(index, { url: String($event ?? '') })"
                    />
                    <Select
                        :model-value="asButton(block).align"
                        :label="t('emailMarketing.editor.align')"
                        :options="alignOptions"
                        :disabled="disabled"
                        @update:model-value="patch(index, { align: String($event) as 'left' | 'center' })"
                    />
                    <Select
                        :model-value="asButton(block).style"
                        :label="t('emailMarketing.editor.style')"
                        :options="[
                            { value: 'primary', label: t('emailMarketing.editor.stylePrimary') },
                            { value: 'dark', label: t('emailMarketing.editor.styleDark') },
                        ]"
                        :disabled="disabled"
                        @update:model-value="patch(index, { style: String($event) as 'primary' | 'dark' })"
                    />
                </div>

                <p v-else-if="block.type === 'divider'" class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.editor.dividerHint') }}</p>

                <div v-else-if="block.type === 'spacer'" class="grid gap-3 @md:grid-cols-2">
                    <Input
                        type="number"
                        min="8"
                        max="96"
                        :model-value="asSpacer(block).height"
                        :label="t('emailMarketing.editor.spacerHeight')"
                        :disabled="disabled"
                        :error="fieldError(index, 'height')"
                        @update:model-value="patch(index, { height: Math.min(96, Math.max(8, Number($event) || 24)) })"
                    />
                </div>
            </li>
        </ol>

        <div class="mt-4 flex flex-wrap items-center gap-2" role="group" :aria-label="t('emailMarketing.editor.addBlock')">
            <span class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.editor.addBlock') }}</span>
            <Button
                v-for="type in BLOCK_TYPES"
                :key="type"
                size="sm"
                :disabled="disabled || blocks.length >= max"
                @click="add(type)"
            >
                {{ t(`emailMarketing.editor.types.${type}`) }}
            </Button>
        </div>
        <p v-if="blocks.length >= max" class="mt-2 text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.editor.maxBlocks', { count: max }) }}</p>
    </div>
</template>

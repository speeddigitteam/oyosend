<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LayoutList, Link2, ListChecks, Paperclip, Send } from 'lucide-vue-next';
import { type Component, computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import NumberPicker from './NumberPicker.vue';
import type { NumberPicker as NumberPickerData } from './types';

/**
 * Rich replies in the open 24-hour window (ADR 0022): reply buttons, a list, a call-to-action URL button and a
 * media file. Each is built server-side as WhatsappContent and sent through the gateway, which enforces the
 * window. The number picker sets which business number the message goes out from.
 */
const props = defineProps<{
    conversationId: string;
    windowOpen: boolean;
    connected: boolean;
    picker: NumberPickerData;
}>();

type Mode = 'buttons' | 'list' | 'cta' | 'media';

const { t } = useI18n();

const mode = ref<Mode>('buttons');
const senderNumberId = ref(props.picker.defaultId ?? '');

const modes: { value: Mode; label: string; icon: Component }[] = [
    { value: 'buttons', label: 'whatsapp.rich.buttons', icon: ListChecks },
    { value: 'list', label: 'whatsapp.rich.list', icon: LayoutList },
    { value: 'cta', label: 'whatsapp.rich.cta', icon: Link2 },
    { value: 'media', label: 'whatsapp.rich.media', icon: Paperclip },
];

const options = ref<string[]>(['', '']);
const interactive = useForm({ body: '', button: '' });
const cta = useForm({ body: '', label: '', url: '' });
const media = useForm<{ type: string; caption: string; file: File | null }>({ type: 'image', caption: '', file: null });

const maxOptions = computed(() => (mode.value === 'buttons' ? 3 : 10));
const mediaTypes = computed(() => ['image', 'document', 'video', 'audio'].map((value) => ({ value, label: t(`whatsapp.rich.mediaType.${value}`) })));

function addOption(): void {
    if (options.value.length < maxOptions.value) {
        options.value.push('');
    }
}

function removeOption(index: number): void {
    options.value.splice(index, 1);
}

function cleanedOptions(): string[] {
    return options.value.map((option) => option.trim()).filter((option) => option !== '');
}

function sendInteractive(): void {
    interactive
        .transform((data) => ({
            body: data.body,
            sender_number_id: senderNumberId.value || null,
            kind: mode.value,
            button: mode.value === 'list' ? data.button : null,
            options: cleanedOptions(),
        }))
        .post(route('whatsapp_marketing.inbox.interactive', { conversation: props.conversationId }), {
            preserveScroll: true,
            onSuccess: () => {
                interactive.reset();
                options.value = ['', ''];
            },
        });
}

function sendCta(): void {
    cta.transform((data) => ({ ...data, sender_number_id: senderNumberId.value || null })).post(
        route('whatsapp_marketing.inbox.cta', { conversation: props.conversationId }),
        { preserveScroll: true, onSuccess: () => cta.reset() },
    );
}

function sendMedia(): void {
    media.transform((data) => ({ ...data, sender_number_id: senderNumberId.value || null })).post(
        route('whatsapp_marketing.inbox.media', { conversation: props.conversationId }),
        { preserveScroll: true, forceFormData: true, onSuccess: () => media.reset() },
    );
}

function onFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    media.file = target.files && target.files.length > 0 ? target.files[0] : null;
}

const processing = computed(() => interactive.processing || cta.processing || media.processing);
const interactiveReady = computed(() => interactive.body.trim() !== '' && cleanedOptions().length > 0 && (mode.value !== 'list' || interactive.button.trim() !== ''));
</script>

<template>
    <Card v-if="connected && windowOpen" :title="t('whatsapp.rich.title')" data-testid="rich-composer">
        <div class="grid gap-3">
            <div class="flex flex-wrap gap-2" role="tablist" :aria-label="t('whatsapp.rich.title')">
                <Button
                    v-for="item in modes"
                    :key="item.value"
                    :variant="mode === item.value ? 'primary' : 'ghost'"
                    size="sm"
                    role="tab"
                    :aria-selected="mode === item.value"
                    @click="mode = item.value"
                >
                    <template #icon><component :is="item.icon" class="size-4" aria-hidden="true" /></template>
                    {{ t(item.label) }}
                </Button>
            </div>

            <NumberPicker v-model="senderNumberId" :picker="picker" />

            <form v-if="mode === 'buttons' || mode === 'list'" class="grid gap-3" @submit.prevent="sendInteractive">
                <Textarea v-model="interactive.body" :label="t('whatsapp.composer.message')" :error="interactive.errors.body" :rows="3" :maxlength="1024" />
                <Input v-if="mode === 'list'" v-model="interactive.button" :label="t('whatsapp.rich.listButton')" :error="interactive.errors.button" maxlength="20" />
                <div class="grid gap-2">
                    <p class="eyebrow">{{ mode === 'buttons' ? t('whatsapp.rich.buttonsLabel') : t('whatsapp.rich.rowsLabel') }}</p>
                    <div v-for="(_, index) in options" :key="index" class="flex items-center gap-2">
                        <Input v-model="options[index]" class="grow" :label="t('whatsapp.rich.optionLabel', { n: index + 1 })" :maxlength="mode === 'buttons' ? 20 : 24" />
                        <Button v-if="options.length > 1" variant="ghost" size="sm" type="button" @click="removeOption(index)">{{ t('whatsapp.common.delete') }}</Button>
                    </div>
                    <div><Button v-if="options.length < maxOptions" variant="secondary" size="sm" type="button" @click="addOption">{{ t('whatsapp.rich.addOption') }}</Button></div>
                </div>
                <div>
                    <Button type="submit" variant="primary" :loading="processing" :disabled="!interactiveReady">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.composer.send') }}
                    </Button>
                </div>
            </form>

            <form v-else-if="mode === 'cta'" class="grid gap-3" @submit.prevent="sendCta">
                <Textarea v-model="cta.body" :label="t('whatsapp.composer.message')" :error="cta.errors.body" :rows="3" :maxlength="1024" />
                <Input v-model="cta.label" :label="t('whatsapp.rich.ctaLabel')" :error="cta.errors.label" maxlength="20" />
                <Input v-model="cta.url" :label="t('whatsapp.rich.ctaUrl')" :error="cta.errors.url" type="url" inputmode="url" maxlength="2000" />
                <div>
                    <Button type="submit" variant="primary" :loading="processing" :disabled="cta.body.trim() === '' || cta.label.trim() === '' || cta.url.trim() === ''">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.composer.send') }}
                    </Button>
                </div>
            </form>

            <form v-else class="grid gap-3" @submit.prevent="sendMedia">
                <Select v-model="media.type" :options="mediaTypes" :label="t('whatsapp.rich.mediaTypeLabel')" />
                <div class="grid gap-1">
                    <label class="text-sm font-medium text-ink" for="rich-media-file">{{ t('whatsapp.rich.file') }}</label>
                    <input id="rich-media-file" type="file" class="text-sm text-ink" data-testid="rich-media-file" @change="onFile" />
                    <p v-if="media.errors.file" class="text-xs text-danger" role="alert">{{ media.errors.file }}</p>
                </div>
                <Input v-if="media.type !== 'audio'" v-model="media.caption" :label="t('whatsapp.rich.caption')" :error="media.errors.caption" optional maxlength="1024" />
                <div>
                    <Button type="submit" variant="primary" :loading="processing" :disabled="media.file === null">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.composer.send') }}
                    </Button>
                </div>
            </form>
        </div>
    </Card>
</template>

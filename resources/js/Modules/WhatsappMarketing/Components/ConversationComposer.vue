<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Clock, Send, Sparkles } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { sendJson } from '@/Modules/Automation/lib/http';
import ChoicesEditor from './ChoicesEditor.vue';
import NumberPicker from './NumberPicker.vue';
import TemplateSendFields from './TemplateSendFields.vue';
import type { ChannelInfo, NumberPicker as NumberPickerData, QuickReplyRow, SendableTemplate } from './types';
import { useWhatsappFormat } from './useWhatsappFormat';
import { appendText, CHOICE_LIMITS, choicesPayload, choicesValid, emptyChoices, windowRemaining } from './whatsappFormat';

/**
 * Free text while the 24-hour customer-service window is open, optionally with reply buttons or a list; an
 * approved template when it is not. The window is the server's (`windowOpen`/`windowExpiresAt`); the countdown
 * only mirrors it, and the server decides. "Draft with AI" fills the text box only: the agent reads, edits and
 * sends it like any reply (ADR 0018).
 */
const props = withDefaults(
    defineProps<{
        conversationId: string;
        windowOpen: boolean;
        windowExpiresAt: string | null;
        hasContact: boolean;
        canReply: boolean;
        templates: SendableTemplate[];
        quickReplies: QuickReplyRow[];
        channel: ChannelInfo;
        canDraft?: boolean;
        numbers?: NumberPickerData;
    }>(),
    { canDraft: false, numbers: () => ({ options: [], defaultId: null }) },
);

const senderNumberId = ref(props.numbers.defaultId ?? '');

const MAX_LENGTH = 4096;

const { t } = useI18n();
const format = useFormat();
const { duration } = useWhatsappFormat();

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 30_000);
});
onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const remaining = computed(() => windowRemaining(props.windowExpiresAt, now.value));
const open = computed(() => props.windowOpen && remaining.value.open);
const connected = computed(() => props.channel.state === 'available');

const reply = useForm({ body: '', quick_reply_id: '' as string });
const choices = ref(emptyChoices());
const maxLength = computed(() => (choices.value.kind === '' ? MAX_LENGTH : CHOICE_LIMITS.body));
const replyReady = computed(() => reply.body.trim() !== '' && reply.body.length <= maxLength.value && choicesValid(choices.value));
const template = useForm<{ template_id: string; variables: string[] }>({ template_id: '', variables: [] });
const quickId = ref('');

const quickOptions = computed(() => [
    { value: '', label: t('whatsapp.composer.chooseQuickReply') },
    ...props.quickReplies.map((reply) => ({ value: reply.id, label: `${reply.shortcut}: ${reply.title}` })),
]);

function insertQuickReply(): void {
    const picked = props.quickReplies.find((item) => item.id === quickId.value);

    if (picked) {
        reply.body = appendText(reply.body, picked.body).slice(0, MAX_LENGTH);
        reply.quick_reply_id = picked.id;
    }

    quickId.value = '';
}

watch(quickId, (value) => {
    if (value !== '') {
        insertQuickReply();
    }
});

function sendReply(): void {
    const interactive = choicesPayload(choices.value);

    reply
        .transform((data) => ({ body: data.body, quick_reply_id: data.quick_reply_id, ...(senderNumberId.value ? { sender_number_id: senderNumberId.value } : {}), ...(interactive === null ? {} : { interactive }) }))
        .post(route('whatsapp_marketing.inbox.reply', { conversation: props.conversationId }), {
            preserveScroll: true,
            onSuccess: () => {
                reply.reset();
                choices.value = emptyChoices();
                drafted.value = false;
            },
        });
}

const drafting = ref(false);
const drafted = ref(false);
const draftError = ref<string | null>(null);

/** Ask the AI app for a reply draft from this conversation; the text lands in the box, nothing is sent. */
async function draft(): Promise<void> {
    drafting.value = true;
    draftError.value = null;

    const response = await sendJson<{ text: string }>('POST', route('ai.drafts.store'), {
        kind: 'draft_reply',
        record_type: 'whatsapp_conversation',
        record_id: props.conversationId,
    });

    drafting.value = false;

    if (response.ok && response.data) {
        reply.body = response.data.text.slice(0, maxLength.value);
        drafted.value = true;

        return;
    }

    draftError.value = response.status === 404 ? t('whatsapp.composer.aiNotAllowed') : (response.message ?? t('whatsapp.composer.aiFailed'));
}

const templateReady = computed(() => {
    const picked = props.templates.find((item) => item.id === template.template_id);

    return picked !== undefined && template.variables.length >= picked.variableCount && template.variables.slice(0, picked.variableCount).every((value) => value.trim() !== '');
});

function sendTemplate(): void {
    template
        .transform((data) => ({ ...data, ...(senderNumberId.value ? { sender_number_id: senderNumberId.value } : {}) }))
        .post(route('whatsapp_marketing.inbox.template', { conversation: props.conversationId }), {
            preserveScroll: true,
            onSuccess: () => template.reset(),
        });
}

const templateError = computed(() => {
    const errors = template.errors as Record<string, string | undefined>;

    return errors.template_id ?? Object.entries(errors).find(([key]) => key.startsWith('variables'))?.[1];
});
</script>

<template>
    <Card :title="t('whatsapp.composer.title')">
        <p v-if="!canReply" class="text-sm text-ink-muted" data-testid="composer-readonly">{{ t('whatsapp.composer.readOnly') }}</p>

        <p v-else-if="!connected" class="text-sm text-ink-muted" data-testid="composer-not-connected">{{ t('whatsapp.composer.notConnected') }}</p>

        <p v-else-if="!hasContact" class="text-sm text-ink-muted" data-testid="composer-no-contact">{{ t('whatsapp.composer.noContact') }}</p>

        <form v-else-if="open" class="grid gap-3" data-testid="composer-free-text" @submit.prevent="sendReply">
            <p class="flex items-center gap-2 text-xs text-ink-subtle">
                <Clock class="size-3.5" aria-hidden="true" />
                {{ t('whatsapp.composer.windowOpen', { time: duration(remaining.hours, remaining.minutes) }) }}
            </p>
            <NumberPicker v-model="senderNumberId" :picker="numbers" />
            <Select v-if="quickReplies.length > 0" v-model="quickId" :options="quickOptions" :label="t('whatsapp.composer.quickReply')" />
            <Textarea
                v-model="reply.body"
                :label="t('whatsapp.composer.message')"
                :hint="t('whatsapp.composer.placeholdersHint')"
                :error="reply.errors.body"
                :rows="4"
                :maxlength="maxLength"
            />
            <p class="text-xs text-ink-subtle tabular">{{ t('whatsapp.composer.count', { used: format.number(reply.body.length), max: format.number(maxLength) }) }}</p>
            <p v-if="drafted" class="text-xs text-warn" data-testid="ai-draft-label">{{ t('whatsapp.composer.aiLabel') }}</p>
            <p v-if="draftError" class="text-xs text-danger" role="alert" data-testid="ai-draft-error">{{ draftError }}</p>
            <p v-if="choices.kind !== '' && reply.body.length > CHOICE_LIMITS.body" class="text-xs text-danger">
                {{ t('whatsapp.composer.bodyLimit', { max: format.number(CHOICE_LIMITS.body) }) }}
            </p>
            <ChoicesEditor v-model="choices" :errors="reply.errors as Record<string, string | undefined>" />
            <div class="flex flex-wrap gap-2">
                <Button type="submit" variant="primary" :loading="reply.processing" :disabled="!replyReady">
                    <template #icon><Send class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.composer.send') }}
                </Button>
                <Button v-if="canDraft" variant="secondary" :loading="drafting" data-testid="ai-draft" @click="draft">
                    <template #icon><Sparkles class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.composer.aiDraft') }}
                </Button>
            </div>
        </form>

        <form v-else class="grid gap-3" data-testid="composer-template" @submit.prevent="sendTemplate">
            <p class="text-sm text-ink-muted" data-testid="window-closed">{{ t('whatsapp.composer.windowClosed') }}</p>
            <p v-if="templates.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.composer.noTemplates') }}</p>
            <template v-else>
                <NumberPicker v-model="senderNumberId" :picker="numbers" />
                <TemplateSendFields v-model:template-id="template.template_id" v-model:values="template.variables" :templates="templates" :error="templateError" />
                <div>
                    <Button type="submit" variant="primary" :loading="template.processing" :disabled="!templateReady">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.composer.sendTemplate') }}
                    </Button>
                </div>
            </template>
        </form>
    </Card>
</template>

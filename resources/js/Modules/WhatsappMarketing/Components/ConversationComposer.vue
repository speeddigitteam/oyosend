<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Clock, Send } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import TemplateSendFields from './TemplateSendFields.vue';
import type { ChannelInfo, QuickReplyRow, SendableTemplate } from './types';
import { useWhatsappFormat } from './useWhatsappFormat';
import { appendText, windowRemaining } from './whatsappFormat';

/**
 * Free text while the 24-hour customer-service window is open; an approved template when it is not. The window
 * is the server's (`windowOpen`/`windowExpiresAt`); the countdown only mirrors it, and the server decides.
 */
const props = defineProps<{
    conversationId: string;
    windowOpen: boolean;
    windowExpiresAt: string | null;
    hasContact: boolean;
    canReply: boolean;
    templates: SendableTemplate[];
    quickReplies: QuickReplyRow[];
    channel: ChannelInfo;
}>();

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
    reply.post(route('whatsapp_marketing.inbox.reply', { conversation: props.conversationId }), {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

const templateReady = computed(() => {
    const picked = props.templates.find((item) => item.id === template.template_id);

    return picked !== undefined && template.variables.length >= picked.variableCount && template.variables.slice(0, picked.variableCount).every((value) => value.trim() !== '');
});

function sendTemplate(): void {
    template.post(route('whatsapp_marketing.inbox.template', { conversation: props.conversationId }), {
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
            <Select v-if="quickReplies.length > 0" v-model="quickId" :options="quickOptions" :label="t('whatsapp.composer.quickReply')" />
            <Textarea
                v-model="reply.body"
                :label="t('whatsapp.composer.message')"
                :hint="t('whatsapp.composer.placeholdersHint')"
                :error="reply.errors.body"
                :rows="4"
                :maxlength="MAX_LENGTH"
            />
            <p class="text-xs text-ink-subtle tabular">{{ t('whatsapp.composer.count', { used: format.number(reply.body.length), max: format.number(MAX_LENGTH) }) }}</p>
            <div>
                <Button type="submit" variant="primary" :loading="reply.processing" :disabled="reply.body.trim() === ''">
                    <template #icon><Send class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.composer.send') }}
                </Button>
            </div>
        </form>

        <form v-else class="grid gap-3" data-testid="composer-template" @submit.prevent="sendTemplate">
            <p class="text-sm text-ink-muted" data-testid="window-closed">{{ t('whatsapp.composer.windowClosed') }}</p>
            <p v-if="templates.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.composer.noTemplates') }}</p>
            <template v-else>
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

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Clock, MessageCircle, RefreshCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelBanner from '../../Components/ChannelBanner.vue';
import ConversationComposer from '../../Components/ConversationComposer.vue';
import ConversationSidePanel from '../../Components/ConversationSidePanel.vue';
import MessageBubble from '../../Components/MessageBubble.vue';
import type { AgentOption, ChannelInfo, ConversationDetail, MessageRow, QuickReplyRow, SendableTemplate } from '../../Components/types';
import { useWhatsappFormat } from '../../Components/useWhatsappFormat';
import { windowRemaining } from '../../Components/whatsappFormat';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    conversation: ConversationDetail;
    messages: MessageRow[];
    templates: SendableTemplate[];
    quickReplies: QuickReplyRow[];
    agents: AgentOption[];
    phoneContacts: { id: string; name: string; phone: string | null }[];
    channel: ChannelInfo;
    can: { reply: boolean; assign: boolean; assignOthers: boolean; manage: boolean; isAgent: boolean; createContact: boolean };
}>();

const { t } = useI18n();
const { duration } = useWhatsappFormat();

const refreshing = ref(false);
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 30_000);

    if (props.conversation.unread > 0) {
        router.post(route('whatsapp_marketing.inbox.read', { conversation: props.conversation.id }), {}, { preserveScroll: true, preserveState: true, only: ['conversation'] });
    }
});
onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

function refresh(): void {
    refreshing.value = true;
    router.reload({ onFinish: () => (refreshing.value = false) });
}

const remaining = computed(() => windowRemaining(props.conversation.windowExpiresAt, now.value));
const windowOpen = computed(() => props.conversation.windowOpen && remaining.value.open);
</script>

<template>
    <PageHeader
        :eyebrow="t('whatsapp.eyebrow')"
        :title="conversation.displayName"
        :description="conversation.contact ? conversation.phone : conversation.profileName ? t('whatsapp.thread.unknownNumber') : undefined"
    >
        <template #actions>
            <Button variant="ghost" :href="route('whatsapp_marketing.inbox.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.thread.back') }}
            </Button>
            <Button variant="secondary" :loading="refreshing" @click="refresh">
                <template #icon><RefreshCw class="size-4" aria-hidden="true" /></template>
                {{ t('whatsapp.thread.refresh') }}
            </Button>
        </template>
    </PageHeader>
    <WhatsappNav current="inbox" />
    <ChannelBanner :channel="channel" />

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="grid min-w-0 content-start gap-3">
            <Card :title="t('whatsapp.thread.title')">
                <template #actions>
                    <Badge v-if="windowOpen" tone="ok" data-testid="window-badge">
                        <Clock class="mr-1 size-3.5" aria-hidden="true" />
                        {{ t('whatsapp.thread.windowOpen', { time: duration(remaining.hours, remaining.minutes) }) }}
                    </Badge>
                    <Badge v-else tone="neutral" data-testid="window-badge">{{ t('whatsapp.thread.windowClosed') }}</Badge>
                </template>
                <EmptyState v-if="messages.length === 0" :title="t('whatsapp.thread.empty')" compact>
                    <template #icon><MessageCircle /></template>
                </EmptyState>
                <ul v-else class="grid gap-2.5" :aria-label="t('whatsapp.thread.title')">
                    <MessageBubble v-for="message in messages" :key="message.id" :message="message" />
                </ul>
            </Card>

            <ConversationComposer
                :conversation-id="conversation.id"
                :window-open="conversation.windowOpen"
                :window-expires-at="conversation.windowExpiresAt"
                :has-contact="conversation.contact !== null"
                :can-reply="can.reply"
                :templates="templates"
                :quick-replies="quickReplies"
                :channel="channel"
            />
        </div>

        <ConversationSidePanel :conversation="conversation" :agents="agents" :phone-contacts="phoneContacts" :can="can" />
    </div>
</template>

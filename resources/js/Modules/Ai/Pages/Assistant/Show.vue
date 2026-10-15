<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Sparkles, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { canCompose, proposalFieldKey, proposalTone } from '../../Components/aiFormat';
import AiNav from '../../Components/AiNav.vue';
import AvailabilityNotice from '../../Components/AvailabilityNotice.vue';
import ConversationList from '../../Components/ConversationList.vue';
import type { Availability, ChatMessage, ConversationSummary, ProposalRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    conversation: ConversationSummary;
    conversations: ConversationSummary[];
    messages: ChatMessage[];
    proposals: ProposalRow[];
    availability: Availability;
    canConfirm: boolean;
}>();

const { t, te } = useI18n();
const format = useFormat();

const form = useForm({ content: '' });
const confirmDelete = ref(false);
const confirmClear = ref(false);
const busy = ref(false);
const deciding = ref<string | null>(null);

function send(): void {
    if (form.processing || form.content.trim() === '' || !canCompose(props.availability.state)) {
        return;
    }

    form.post(route('ai.assistant.conversations.messages.store', { conversation: props.conversation.id }), {
        preserveScroll: true,
        onSuccess: () => form.reset('content'),
    });
}

function destroy(): void {
    busy.value = true;
    router.delete(route('ai.assistant.conversations.destroy', { conversation: props.conversation.id }), {
        onFinish: () => {
            busy.value = false;
            confirmDelete.value = false;
        },
    });
}

function clearAll(): void {
    busy.value = true;
    router.delete(route('ai.assistant.conversations.clear'), {
        onFinish: () => {
            busy.value = false;
            confirmClear.value = false;
        },
    });
}

function decide(proposal: ProposalRow, action: 'confirm' | 'reject'): void {
    deciding.value = proposal.id;
    router.post(
        route(action === 'confirm' ? 'ai.proposals.confirm' : 'ai.proposals.reject', { proposal: proposal.id }),
        {},
        { preserveScroll: true, onFinish: () => (deciding.value = null) },
    );
}

function toolName(name: string): string {
    return te(`ai.assistant.tool.${name}`) ? t(`ai.assistant.tool.${name}`) : name;
}

function usedTools(message: ChatMessage): string[] {
    return [...new Set(message.toolCalls.map((call) => call.tool).filter((name): name is string => typeof name === 'string'))];
}
</script>

<template>
    <PageHeader :eyebrow="t('ai.eyebrow')" :title="conversation.title || t('ai.assistant.untitled')" :description="t('ai.assistant.privacy')">
        <template #actions>
            <Button variant="ghost" :href="route('ai.assistant.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('ai.conversation.back') }}
            </Button>
            <Button variant="ghost" @click="confirmDelete = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('ai.conversation.delete') }}
            </Button>
        </template>
    </PageHeader>
    <AiNav current="assistant" />
    <AvailabilityNotice :availability="availability" />

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="grid min-w-0 content-start gap-3">
            <Card :title="t('ai.conversation.thread')">
                <EmptyState v-if="messages.length === 0" :title="t('ai.conversation.empty')" compact>
                    <template #icon><Sparkles /></template>
                </EmptyState>
                <ol v-else class="grid gap-3" data-testid="messages">
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        :data-role="message.role"
                        :class="['rounded-card px-4 py-3', message.role === 'user' ? 'bg-hover' : 'border border-line']"
                    >
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <span class="text-[0.8125rem] font-semibold text-ink">
                                {{ message.role === 'user' ? t('ai.conversation.you') : t('ai.conversation.assistant') }}
                            </span>
                            <Badge v-if="message.aiGenerated" tone="accent">{{ t('ai.common.generated') }}</Badge>
                            <span v-if="message.createdAt" class="text-xs text-ink-subtle">{{ format.relative(message.createdAt) }}</span>
                        </div>
                        <!-- Plain text interpolation: model output is never rendered as HTML. -->
                        <p class="text-sm break-words whitespace-pre-wrap text-ink">{{ message.content }}</p>
                        <p v-if="usedTools(message).length > 0" class="mt-2 text-xs text-ink-subtle">
                            {{ t('ai.conversation.usedTools') }}:
                            {{ usedTools(message).map(toolName).join(', ') }}
                        </p>
                    </li>
                </ol>
            </Card>

            <Card v-if="proposals.length > 0" :title="t('ai.proposal.title')" :description="t('ai.proposal.hint')">
                <ul class="grid gap-3" data-testid="proposals">
                    <li v-for="proposal in proposals" :key="proposal.id" class="rounded-card border border-line px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[0.8125rem] font-semibold text-ink">{{ toolName(proposal.tool) }}</span>
                            <Badge :tone="proposalTone(proposal.status)">{{ t(`ai.proposal.status.${proposal.pending ? 'pending' : proposal.status}`) }}</Badge>
                        </div>
                        <p class="mt-1.5 text-sm break-words whitespace-pre-wrap text-ink">{{ proposal.summary }}</p>
                        <dl v-if="proposal.pending && proposal.changes.length > 0" class="mt-2 grid gap-2" data-testid="proposal-changes">
                            <div v-for="change in proposal.changes" :key="change.field" class="rounded-control bg-control-sunken px-2.5 py-2 text-xs">
                                <dt class="font-semibold text-ink">{{ te(proposalFieldKey(change.field)) ? t(proposalFieldKey(change.field)) : change.field }}</dt>
                                <dd v-if="change.old !== null" class="mt-0.5 break-words whitespace-pre-wrap text-ink-subtle">
                                    {{ t('ai.proposal.oldValue') }}: {{ change.old }}
                                </dd>
                                <dd class="mt-0.5 break-words whitespace-pre-wrap text-ink">
                                    {{ t('ai.proposal.newValue') }}: {{ change.new ?? t('ai.proposal.emptyValue') }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-1 text-xs text-ink-subtle">
                            <template v-if="proposal.pending">{{ t('ai.proposal.expires', { time: format.relative(proposal.expiresAt) }) }}</template>
                            <template v-else-if="proposal.decidedAt">{{ t('ai.proposal.decided', { time: format.relative(proposal.decidedAt) }) }}</template>
                        </p>
                        <div v-if="proposal.pending" class="mt-3 flex flex-wrap items-center gap-2">
                            <Button
                                v-if="canConfirm"
                                size="sm"
                                variant="primary"
                                :loading="deciding === proposal.id"
                                :disabled="deciding !== null"
                                @click="decide(proposal, 'confirm')"
                            >
                                {{ t('ai.proposal.confirm') }}
                            </Button>
                            <Button size="sm" variant="ghost" :disabled="deciding !== null" @click="decide(proposal, 'reject')">
                                {{ t('ai.proposal.reject') }}
                            </Button>
                            <p v-if="!canConfirm" class="text-xs text-ink-subtle">{{ t('ai.proposal.cannotConfirm') }}</p>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card>
                <form class="grid gap-3" @submit.prevent="send">
                    <Textarea
                        v-model="form.content"
                        :label="t('ai.conversation.replyLabel')"
                        :placeholder="t('ai.conversation.replyPlaceholder')"
                        :hint="t('ai.assistant.shortcut')"
                        :error="form.errors.content ?? (form.errors as Record<string, string>).ai"
                        :rows="3"
                        :disabled="!canCompose(availability.state)"
                        data-testid="reply"
                        @keydown.ctrl.enter.prevent="send"
                        @keydown.meta.enter.prevent="send"
                    />
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            variant="primary"
                            :loading="form.processing"
                            :disabled="!canCompose(availability.state) || form.content.trim() === ''"
                        >
                            {{ t('ai.assistant.send') }}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>

        <ConversationList :conversations="conversations" :current-id="conversation.id" @clear="confirmClear = true" />
    </div>

    <ConfirmDialog
        v-model:open="confirmDelete"
        :title="t('ai.conversation.deleteTitle')"
        :description="t('ai.conversation.deleteBody')"
        :confirm-label="t('ai.conversation.deleteConfirm')"
        :loading="busy"
        @confirm="destroy"
    />
    <ConfirmDialog
        v-model:open="confirmClear"
        :title="t('ai.assistant.clearTitle')"
        :description="t('ai.assistant.clearBody')"
        :confirm-label="t('ai.assistant.clearConfirm')"
        :loading="busy"
        @confirm="clearAll"
    />
</template>

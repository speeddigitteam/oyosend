<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageSquareText } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { ConversationSummary } from './types';

defineProps<{ conversations: ConversationSummary[]; currentId?: string }>();
defineEmits<{ clear: [] }>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('ai.assistant.history')" flush>
        <template v-if="conversations.length > 0" #actions>
            <button type="button" class="text-[0.8125rem] font-medium text-danger hover:underline" @click="$emit('clear')">
                {{ t('ai.assistant.clearAll') }}
            </button>
        </template>
        <EmptyState v-if="conversations.length === 0" :title="t('ai.assistant.historyEmpty')" :description="t('ai.assistant.historyEmptyBody')" compact>
            <template #icon><MessageSquareText /></template>
        </EmptyState>
        <ul v-else class="max-h-[28rem] overflow-y-auto px-2 pb-2">
            <li v-for="conversation in conversations" :key="conversation.id">
                <Link
                    :href="route('ai.assistant.conversations.show', { conversation: conversation.id })"
                    :aria-current="conversation.id === currentId ? 'page' : undefined"
                    :class="[
                        'block rounded-control px-3 py-2 text-sm hover:bg-hover',
                        conversation.id === currentId ? 'bg-hover text-ink' : 'text-ink-muted',
                    ]"
                >
                    <span class="block truncate font-medium text-ink">{{ conversation.title || t('ai.assistant.untitled') }}</span>
                    <span v-if="conversation.lastMessageAt" class="block text-xs text-ink-subtle">
                        {{ t('ai.assistant.updated', { time: format.relative(conversation.lastMessageAt) }) }}
                    </span>
                </Link>
            </li>
        </ul>
    </Card>
</template>

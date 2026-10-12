<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { canCompose } from '../../Components/aiFormat';
import AiNav from '../../Components/AiNav.vue';
import AvailabilityNotice from '../../Components/AvailabilityNotice.vue';
import ConversationList from '../../Components/ConversationList.vue';
import type { Availability, ConversationSummary, ToolInfo } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    conversations: ConversationSummary[];
    availability: Availability;
    tools: ToolInfo[];
    canConfirm: boolean;
}>();

const { t, te } = useI18n();

const form = useForm({ content: '' });
const confirmClear = ref(false);
const clearing = ref(false);

function start(): void {
    if (form.processing || form.content.trim() === '' || !canCompose(props.availability.state)) {
        return;
    }

    form.post(route('ai.assistant.conversations.store'), { preserveScroll: true });
}

function clearAll(): void {
    clearing.value = true;
    router.delete(route('ai.assistant.conversations.clear'), {
        onFinish: () => {
            clearing.value = false;
            confirmClear.value = false;
        },
    });
}

function toolLabel(tool: ToolInfo): string {
    return te(`ai.assistant.tool.${tool.name}`) ? t(`ai.assistant.tool.${tool.name}`) : tool.description;
}
</script>

<template>
    <PageHeader :eyebrow="t('ai.eyebrow')" :title="t('ai.assistant.title')" :description="t('ai.assistant.description')" />
    <AiNav current="assistant" />
    <AvailabilityNotice :availability="availability" />

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="grid min-w-0 content-start gap-3">
            <Card :title="t('ai.assistant.newTitle')" :description="t('ai.assistant.privacy')">
                <form class="grid gap-3" @submit.prevent="start">
                    <Textarea
                        v-model="form.content"
                        :label="t('ai.assistant.newLabel')"
                        :placeholder="t('ai.assistant.newPlaceholder')"
                        :hint="t('ai.assistant.shortcut')"
                        :error="form.errors.content ?? (form.errors as Record<string, string>).ai"
                        :rows="4"
                        :disabled="!canCompose(availability.state)"
                        data-testid="new-message"
                        @keydown.ctrl.enter.prevent="start"
                        @keydown.meta.enter.prevent="start"
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

            <Card :title="t('ai.assistant.toolsTitle')" :description="t('ai.assistant.toolsHint')">
                <p v-if="tools.length === 0" class="text-sm text-ink-muted">{{ t('ai.assistant.toolsEmpty') }}</p>
                <ul v-else class="grid gap-2 sm:grid-cols-2" data-testid="tools">
                    <li v-for="tool in tools" :key="tool.name" class="flex items-center justify-between gap-2 rounded-control bg-hover px-3 py-2 text-sm">
                        <span class="min-w-0 truncate text-ink">{{ toolLabel(tool) }}</span>
                        <Badge :tone="tool.proposes ? 'warn' : 'neutral'">
                            {{ tool.proposes ? t('ai.assistant.toolPropose') : t('ai.assistant.toolRead') }}
                        </Badge>
                    </li>
                </ul>
            </Card>
        </div>

        <ConversationList :conversations="conversations" @clear="confirmClear = true" />
    </div>

    <ConfirmDialog
        v-model:open="confirmClear"
        :title="t('ai.assistant.clearTitle')"
        :description="t('ai.assistant.clearBody')"
        :confirm-label="t('ai.assistant.clearConfirm')"
        :loading="clearing"
        @confirm="clearAll"
    />
</template>

<script setup lang="ts">
import { ArrowUpRight, MessageCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

defineOptions({ name: 'WhatsappOverviewWidget' });

defineProps<{
    data: { open: number; unassigned: number; mine: number; unread: number; campaigns: number; connected: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('whatsapp.widget.title')" class="h-full">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('whatsapp_marketing.inbox.index')">
                {{ t('whatsapp.widget.view') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>
        <p v-if="!data.connected" class="mb-3 text-sm text-ink-muted" data-testid="widget-not-connected">{{ t('whatsapp.widget.notConnected') }}</p>
        <EmptyState v-if="data.open === 0" :title="t('whatsapp.widget.empty')" compact>
            <template #icon><MessageCircle /></template>
        </EmptyState>
        <dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div>
                <dt class="eyebrow">{{ t('whatsapp.widget.open') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.open) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.widget.mine') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.mine) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.widget.unassigned') }}</dt>
                <dd class="figure mt-2 text-2xl text-brand-text">{{ format.number(data.unassigned) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('whatsapp.widget.unread') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.unread) }}</dd>
            </div>
        </dl>
        <p v-if="data.campaigns > 0" class="mt-4 text-sm text-ink-muted" data-testid="widget-campaigns">
            {{ t('whatsapp.widget.campaigns', { count: format.number(data.campaigns) }) }}
        </p>
    </Card>
</template>

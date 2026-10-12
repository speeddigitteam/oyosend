<script setup lang="ts">
import { ArrowUpRight, LifeBuoy } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

defineProps<{
    data: { open: number; unassigned: number; mine: number; waitingOnCustomer: number; breached: number };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('support.widget.title')" class="h-full">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('support.tickets.index')">
                {{ t('support.widget.view') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>
        <EmptyState v-if="data.open === 0" :title="t('support.widget.empty')" compact>
            <template #icon><LifeBuoy /></template>
        </EmptyState>
        <dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div>
                <dt class="eyebrow">{{ t('support.widget.open') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.open) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('support.widget.mine') }}</dt>
                <dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.mine) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('support.widget.unassigned') }}</dt>
                <dd class="figure mt-2 text-2xl text-brand-text">{{ format.number(data.unassigned) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('support.widget.waiting') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.waitingOnCustomer) }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="eyebrow">{{ t('support.widget.breached') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.breached > 0 ? 'text-danger' : 'text-ink'">
                    {{ format.number(data.breached) }}
                </dd>
            </div>
        </dl>
    </Card>
</template>

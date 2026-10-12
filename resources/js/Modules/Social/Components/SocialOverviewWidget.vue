<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** What is queued, what needs a decision and which accounts need attention. */
const props = defineProps<{
    data: {
        scheduled: number;
        awaitingApproval: number;
        failed: number;
        accountsNeedingAttention: number;
        nextScheduledFor: string | null;
    };
}>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const tiles = computed(() => [
    { key: 'scheduled', value: props.data.scheduled, attention: false },
    { key: 'awaitingApproval', value: props.data.awaitingApproval, attention: props.data.awaitingApproval > 0 },
    { key: 'failed', value: props.data.failed, attention: props.data.failed > 0 },
    { key: 'accountsNeedingAttention', value: props.data.accountsNeedingAttention, attention: props.data.accountsNeedingAttention > 0 },
]);
</script>

<template>
    <Card :title="t('social.widget.overview.title')" class="h-full" data-testid="social-overview-widget">
        <template #actions>
            <Button v-if="can('social.posts.view')" size="sm" variant="ghost" :href="route('social.calendar.index')">
                {{ t('social.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div v-for="tile in tiles" :key="tile.key">
                <dt class="eyebrow">{{ t(`social.widget.overview.${tile.key}`) }}</dt>
                <dd class="figure mt-2 text-2xl" :class="tile.attention ? 'text-brand-text' : 'text-ink'">{{ format.number(tile.value) }}</dd>
            </div>
        </dl>

        <p class="mt-4 text-[0.8125rem] text-ink-muted" data-testid="social-next">
            <template v-if="data.nextScheduledFor">
                {{ t('social.widget.overview.next', { when: format.date(data.nextScheduledFor, 'datetime') }) }}
            </template>
            <template v-else>{{ t('social.widget.overview.nothingNext') }}</template>
        </p>
    </Card>
</template>

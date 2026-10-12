<script setup lang="ts">
import { ArrowUpRight, UserRound } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** What waits for the signed-in person in Talent: tasks, reviews, goals and training. */
defineProps<{
    data: {
        hasProfile: boolean;
        pendingTasks: number;
        reviewsToWrite: number;
        reviewsToAcknowledge: number;
        activeGoals: number;
        openEnrolments: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('talent.widget.self.title')" class="h-full" data-testid="talent-self-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('talent.me')">
                {{ t('talent.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <EmptyState v-if="!data.hasProfile" :title="t('talent.widget.self.noProfile')" :description="t('talent.widget.self.noProfileBody')" compact>
            <template #icon><UserRound /></template>
        </EmptyState>

        <dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4">
            <div>
                <dt class="eyebrow">{{ t('talent.widget.self.tasks') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.pendingTasks > 0 ? 'text-brand-text' : 'text-ink'" data-testid="self-tasks">
                    {{ format.number(data.pendingTasks) }}
                </dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('talent.widget.self.reviewsToWrite') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.reviewsToWrite > 0 ? 'text-brand-text' : 'text-ink'">
                    {{ format.number(data.reviewsToWrite) }}
                </dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('talent.widget.self.reviewsToAcknowledge') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.reviewsToAcknowledge > 0 ? 'text-brand-text' : 'text-ink'">
                    {{ format.number(data.reviewsToAcknowledge) }}
                </dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('talent.widget.self.goals') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.activeGoals) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('talent.widget.self.training') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.openEnrolments) }}</dd>
            </div>
        </dl>
    </Card>
</template>

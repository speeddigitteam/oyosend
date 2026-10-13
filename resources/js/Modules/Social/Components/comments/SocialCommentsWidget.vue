<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

/** How many audience comments still wait for a person. */
defineProps<{ data: { unhandled: number; unread: number; needsAttention: number } }>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('socialComments.widget.title')" class="h-full" data-testid="social-comments-widget">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('social.comments.index')">
                {{ t('social.widget.open') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>
        <dl class="grid grid-cols-3 gap-x-4">
            <div>
                <dt class="eyebrow">{{ t('socialComments.widget.unhandled') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.unhandled > 0 ? 'text-brand-text' : 'text-ink'">{{ format.number(data.unhandled) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('socialComments.widget.unread') }}</dt>
                <dd class="figure mt-2 text-2xl text-ink">{{ format.number(data.unread) }}</dd>
            </div>
            <div>
                <dt class="eyebrow">{{ t('socialComments.widget.needsAttention') }}</dt>
                <dd class="figure mt-2 text-2xl" :class="data.needsAttention > 0 ? 'text-brand-text' : 'text-ink'">{{ format.number(data.needsAttention) }}</dd>
            </div>
        </dl>
    </Card>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { CapturePage, MessagingAvailability } from './messageTypes';

/**
 * Per connected Facebook Page: whether its direct messages are captured, with the honest reason when not. Only account
 * managers see the switch; the server checks the permission and every Meta requirement again.
 */
defineProps<{
    pages: CapturePage[];
    availability: MessagingAvailability;
    canManage: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const busy = ref<string | null>(null);

function toggle(page: CapturePage): void {
    busy.value = page.id;
    router.post(route(page.enabled ? 'social.messages.capture.disable' : 'social.messages.capture.enable', { account: page.id }), {}, {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}
</script>

<template>
    <Card :title="t('social.messages.capture.title')" :description="availability.instagram ? t('social.messages.capture.hintInstagram') : t('social.messages.capture.hintMessenger')">
        <ul class="grid gap-3" :aria-label="t('social.messages.capture.title')">
            <li v-for="page in pages" :key="page.id" class="grid gap-1.5 rounded-control border border-line px-3 py-2.5" :data-testid="`capture-${page.id}`">
                <!-- The name wraps instead of being squeezed: the state and the switch move to the next line together. -->
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                    <span class="min-w-0 text-sm font-medium wrap-break-word text-ink">{{ page.name }}</span>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <Badge :tone="page.enabled ? 'ok' : page.state === 'failed' ? 'warn' : 'neutral'">{{ t(`social.messages.capture.states.${page.enabled ? 'on' : page.state}`) }}</Badge>
                        <Button
                            v-if="canManage && availability.problem === null && (page.enabled || page.status === 'connected')"
                            size="sm"
                            :variant="page.enabled ? 'secondary' : 'primary'"
                            :loading="busy === page.id"
                            :disabled="busy !== null"
                            @click="toggle(page)"
                        >
                            {{ page.enabled ? t('social.messages.capture.switchOff') : t('social.messages.capture.switchOn') }}
                        </Button>
                    </div>
                </div>
                <p v-if="page.status !== 'connected'" class="text-xs text-warn">{{ t('social.messages.capture.reconnect') }}</p>
                <p v-if="page.missingScopes.length > 0" class="text-xs text-warn">{{ t('social.messages.capture.missing', { scopes: page.missingScopes.join(', ') }) }}</p>
                <p v-else-if="page.lastError && !page.enabled" class="text-xs text-warn">{{ page.lastError }}</p>
                <p v-if="page.enabled && availability.instagram" class="text-xs text-ink-subtle">{{ t(`social.messages.capture.instagram.${page.instagramState}`) }}</p>
                <p v-if="page.lastEventAt" class="text-xs text-ink-subtle">{{ t('social.messages.capture.lastEvent', { when: format.relative(page.lastEventAt) }) }}</p>
            </li>
        </ul>
        <p v-if="canManage" class="mt-3 text-xs break-all text-ink-subtle">{{ t('social.messages.capture.webhook', { url: availability.webhookUrl }) }}</p>
    </Card>
</template>

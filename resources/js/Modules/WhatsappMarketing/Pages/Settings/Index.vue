<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { PlugZap, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import AgentsPanel, { type AgentRow } from '../../Components/AgentsPanel.vue';
import { safeRoute } from '../../Components/links';
import type { ChannelInfo } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: { businessName: string | null; displayPhone: string | null; autoAssign: boolean; autoCreateContacts: boolean; botsEnabled: boolean; feedbackEnabled?: boolean };
    channel: ChannelInfo;
    agents: AgentRow[];
    members: { id: string; name: string }[];
    limits: { agents: { used: number; limit: number | null }; conversations: { used: number; limit: number | null } };
    guidance: { communicationSettingsRoute: string; webhookPath: string };
}>();

const { t, te } = useI18n();
const format = useFormat();
const { can } = useAccess();

const form = useForm({
    business_name: props.settings.businessName ?? '',
    display_phone: props.settings.displayPhone ?? '',
    auto_assign: props.settings.autoAssign,
    auto_create_contacts: props.settings.autoCreateContacts,
    bots_enabled: props.settings.botsEnabled,
    feedback_enabled: props.settings.feedbackEnabled ?? false,
});

const connected = computed(() => props.channel.state === 'available');
const reasonText = computed(() => {
    const reason = props.channel.reason;

    return reason !== null && te(`whatsapp.settings.reasons.${reason}`) ? t(`whatsapp.settings.reasons.${reason}`) : null;
});
const communicationHref = computed(() => (can('communication.settings.manage') ? safeRoute(props.guidance.communicationSettingsRoute) : null));

function save(): void {
    form
        .transform((data) => ({
            ...data,
            business_name: data.business_name.trim() === '' ? null : data.business_name.trim(),
            display_phone: data.display_phone.trim() === '' ? null : data.display_phone.trim(),
        }))
        .put(route('whatsapp_marketing.settings.update'), { preserveScroll: true });
}

function usage(item: { used: number; limit: number | null }): string {
    return item.limit === null ? t('whatsapp.settings.unlimited', { used: format.number(item.used) }) : t('whatsapp.settings.usedOf', { used: format.number(item.used), limit: format.number(item.limit) });
}
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.settings.title')" :description="t('whatsapp.settings.description')" />
    <WhatsappNav current="settings" />

    <div class="grid gap-3 lg:grid-cols-2">
        <Card :title="t('whatsapp.settings.connection')" class="lg:col-span-2">
            <div class="grid gap-3 text-sm">
                <div class="flex flex-wrap items-center gap-2" data-testid="connection-state">
                    <PlugZap class="size-4 text-ink-muted" aria-hidden="true" />
                    <Badge :tone="connected ? 'ok' : 'warn'" dot>{{ connected ? t('whatsapp.settings.connected') : t('whatsapp.settings.notConnected') }}</Badge>
                    <Badge v-if="channel.testDouble" tone="warn">{{ t('whatsapp.channel.testBadge') }}</Badge>
                </div>
                <p v-if="reasonText" class="text-ink-muted">{{ reasonText }}</p>
                <p v-if="channel.testDouble" class="text-ink-muted">{{ t('whatsapp.channel.testBody') }}</p>

                <div class="rounded-card border border-line bg-hover px-4 py-3">
                    <p class="font-medium text-ink">{{ t('whatsapp.settings.howTitle') }}</p>
                    <ol class="mt-2 grid list-decimal gap-1.5 pl-5 text-ink-muted">
                        <li>{{ t('whatsapp.settings.stepOperator') }}</li>
                        <li>{{ t('whatsapp.settings.stepConnect') }}</li>
                        <li>{{ t('whatsapp.settings.stepWebhook') }}</li>
                        <li>{{ t('whatsapp.settings.stepTemplates') }}</li>
                    </ol>
                    <p class="mt-3 text-ink-muted">
                        {{ t('whatsapp.settings.webhookPath') }}
                        <code class="font-mono text-ink wrap-anywhere" data-testid="webhook-path">{{ guidance.webhookPath }}</code>
                    </p>
                    <p class="mt-2 flex items-start gap-2 text-ink-subtle">
                        <ShieldCheck class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {{ t('whatsapp.settings.secretsNote') }}
                    </p>
                    <p v-if="communicationHref" class="mt-3">
                        <Link :href="communicationHref" class="text-brand-text hover:underline">{{ t('whatsapp.settings.openCommunication') }}</Link>
                    </p>
                    <p class="mt-2">
                        <Link :href="route('whatsapp_marketing.numbers.index')" class="text-brand-text hover:underline" data-testid="manage-numbers-link">{{ t('whatsapp.settings.manageNumbers') }}</Link>
                    </p>
                </div>
            </div>
        </Card>

        <Card :title="t('whatsapp.settings.optionsTitle')">
            <form class="grid gap-4" novalidate @submit.prevent="save">
                <Input v-model="form.business_name" :label="t('whatsapp.settings.businessName')" :hint="t('whatsapp.settings.businessNameHint')" :error="form.errors.business_name" optional maxlength="120" />
                <Input v-model="form.display_phone" :label="t('whatsapp.settings.displayPhone')" :hint="t('whatsapp.settings.displayPhoneHint')" :error="form.errors.display_phone" optional maxlength="32" />
                <Checkbox v-model="form.auto_assign" :label="t('whatsapp.settings.autoAssign')" :description="t('whatsapp.settings.autoAssignHint')" />
                <Checkbox v-model="form.auto_create_contacts" :label="t('whatsapp.settings.autoCreate')" :description="t('whatsapp.settings.autoCreateHint')" />
                <Checkbox v-model="form.bots_enabled" :label="t('whatsapp.settings.bots')" :description="t('whatsapp.settings.botsHint')" />
                <Checkbox v-model="form.feedback_enabled" :label="t('whatsapp.settings.feedback')" :description="t('whatsapp.settings.feedbackHint', { min: format.number(1), max: format.number(5) })" />
                <div><Button type="submit" variant="primary" :loading="form.processing">{{ t('whatsapp.common.save') }}</Button></div>
            </form>
        </Card>

        <Card :title="t('whatsapp.settings.limitsTitle')" :description="t('whatsapp.settings.limitsDescription')">
            <dl class="grid gap-4 text-sm">
                <div>
                    <dt class="eyebrow">{{ t('whatsapp.settings.limitConversations') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink" data-testid="limit-conversations">{{ usage(limits.conversations) }}</dd>
                    <dd class="mt-1 text-ink-subtle">{{ t('whatsapp.settings.limitConversationsHint') }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('whatsapp.settings.limitAgents') }}</dt>
                    <dd class="figure mt-2 text-2xl text-ink">{{ usage(limits.agents) }}</dd>
                </div>
            </dl>
        </Card>

        <div class="lg:col-span-2">
            <AgentsPanel :agents="agents" :members="members" :limit="limits.agents" />
        </div>
    </div>
</template>

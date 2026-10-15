<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ChannelCard, { type ChannelDescription } from '../../Components/ChannelCard.vue';
import CommunicationNav from '../../Components/CommunicationNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    settings: {
        fromName: string | null;
        replyToEmail: string | null;
        quietHoursEnabled: boolean;
        quietHoursStart: string;
        quietHoursEnd: string;
        footerText: string | null;
    };
    channels: ChannelDescription[];
    /** Credential names only; a value never reaches the browser. */
    credentialRefs: string[];
    credentialLabels?: Record<string, string>;
    senderGuidance: { platformSender: string; customDomainVerified: boolean };
}>();

const { t } = useI18n();

const form = useForm({
    from_name: props.settings.fromName ?? '',
    reply_to_email: props.settings.replyToEmail ?? '',
    quiet_hours_enabled: props.settings.quietHoursEnabled,
    quiet_hours_start: props.settings.quietHoursStart.slice(0, 5),
    quiet_hours_end: props.settings.quietHoursEnd.slice(0, 5),
    footer_text: props.settings.footerText ?? '',
});

function save(): void {
    form
        .transform((data) => ({
            ...data,
            from_name: data.from_name.trim() === '' ? null : data.from_name,
            reply_to_email: data.reply_to_email.trim() === '' ? null : data.reply_to_email,
            footer_text: data.footer_text.trim() === '' ? null : data.footer_text,
        }))
        .put(route('communication.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.settings.title')" :description="t('communication.settings.description')" />
    <CommunicationNav current="settings" />

    <div class="grid gap-3">
        <!-- Full width like the platform Settings page: the three setting boxes side by side on wide screens, one save
             for all three below them. Each box lays out its own fields by its own width (container queries). -->
        <form class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3" @submit.prevent="save">
            <Card :title="t('communication.settings.sender')" :description="t('communication.settings.senderHint')">
                <div class="@container flex flex-col gap-4">
                    <div class="grid content-start gap-4 @lg:grid-cols-2">
                        <Input
                            v-model="form.from_name"
                            :label="t('communication.settings.fromName')"
                            :hint="t('communication.settings.fromNameHint')"
                            :error="form.errors.from_name"
                            optional
                            maxlength="120"
                        />
                        <Input
                            v-model="form.reply_to_email"
                            type="email"
                            :label="t('communication.settings.replyTo')"
                            :hint="t('communication.settings.replyToHint')"
                            :error="form.errors.reply_to_email"
                            optional
                            maxlength="190"
                        />
                    </div>
                    <div>
                        <p class="text-sm wrap-break-word text-ink-muted">
                            {{ t('communication.settings.platformSender') }}:
                            <span class="font-mono text-ink">{{ senderGuidance.platformSender }}</span>
                        </p>
                        <p class="mt-2 text-sm text-ink-subtle">{{ t('communication.settings.domainGuidance') }}</p>
                    </div>
                </div>
            </Card>

            <Card :title="t('communication.settings.quietHours')" :description="t('communication.settings.quietHoursHint')">
                <div class="@container flex flex-col gap-4">
                    <Checkbox v-model="form.quiet_hours_enabled" :label="t('communication.settings.quietHoursEnabled')" />
                    <div class="grid content-start gap-4 @lg:grid-cols-2">
                        <Input
                            v-model="form.quiet_hours_start"
                            type="time"
                            :label="t('communication.settings.quietStart')"
                            :error="form.errors.quiet_hours_start"
                            required
                        />
                        <Input
                            v-model="form.quiet_hours_end"
                            type="time"
                            :label="t('communication.settings.quietEnd')"
                            :error="form.errors.quiet_hours_end"
                            required
                        />
                    </div>
                </div>
            </Card>

            <Card class="lg:col-span-2 xl:col-span-1" :title="t('communication.settings.footer')" :description="t('communication.settings.footerHint')">
                <Textarea
                    v-model="form.footer_text"
                    :rows="3"
                    :label="t('communication.settings.footerText')"
                    :error="form.errors.footer_text"
                    optional
                    maxlength="1000"
                />
            </Card>

            <!-- One save for the three boxes above. -->
            <div class="flex justify-end lg:col-span-2 xl:col-span-3">
                <Button type="submit" variant="primary" :loading="form.processing">{{ t('communication.settings.saveSettings') }}</Button>
            </div>
        </form>

        <div class="mt-4">
            <h2 class="text-lg text-ink">{{ t('communication.settings.channels') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ t('communication.settings.channelsHint') }}</p>
        </div>
        <!-- One box per channel; each saves on its own. -->
        <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
            <ChannelCard v-for="channel in channels" :key="channel.channel" :channel="channel" :credential-refs="credentialRefs" :credential-labels="credentialLabels" />
        </div>
    </div>
</template>

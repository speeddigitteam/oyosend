<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Copy, MessageCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SupportNav from '../../Components/SupportNav.vue';

defineOptions({ layout: TenantLayout });

/**
 * Website chat widget settings (ADR 0032): on or off, the websites allowed to embed it, its texts, whether visitors
 * are asked for a name and an email address, and the code to paste. The server validates and normalises every value.
 */
type VisitorDetails = 'none' | 'optional' | 'required';

const props = defineProps<{
    settings: {
        enabled: boolean;
        allowedOrigins: string[];
        widgetTitle: string | null;
        greeting: string | null;
        visitorDetails: VisitorDetails;
        offlineNote: string | null;
    };
    snippet: string;
    visitorDetailOptions: VisitorDetails[];
    maxOrigins: number;
    insecureLocal: boolean;
}>();

const { t } = useI18n();

const form = useForm({
    enabled: props.settings.enabled,
    origins: props.settings.allowedOrigins.join('\n'),
    widget_title: props.settings.widgetTitle ?? '',
    greeting: props.settings.greeting ?? '',
    visitor_details: props.settings.visitorDetails as string,
    offline_note: props.settings.offlineNote ?? '',
});

const copied = ref(false);
const detailOptions = computed(() => props.visitorDetailOptions.map((value) => ({ value, label: t(`support.liveChat.settings.visitorDetailOptions.${value}`) })));

/** The first error about the list or one of its lines (`allowed_origins.2`). */
const originsError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.allowed_origins ?? Object.entries(errors).find(([key]) => key.startsWith('allowed_origins.'))?.[1];
});

function save(): void {
    form.transform((data) => ({
        enabled: data.enabled,
        allowed_origins: data.origins.split('\n').map((line) => line.trim()).filter((line) => line !== ''),
        widget_title: data.widget_title === '' ? null : data.widget_title,
        greeting: data.greeting === '' ? null : data.greeting,
        visitor_details: data.visitor_details,
        offline_note: data.offline_note === '' ? null : data.offline_note,
    })).put(route('support.live_chat.settings.update'), { preserveScroll: true });
}

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.snippet);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <PageHeader :eyebrow="t('support.eyebrow')" :title="t('support.liveChat.settings.title')" :description="t('support.liveChat.settings.description')">
        <template #actions>
            <Button :href="route('support.live_chat.index')">
                <template #icon><MessageCircle class="size-4" aria-hidden="true" /></template>
                {{ t('support.liveChat.settings.back') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="settings" />

    <!-- Full width like the platform Settings page: the three boxes side by side on wide screens, one save for the
         whole widget below them. Each box lays out its own fields by its own width (container queries). -->
    <form class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3" @submit.prevent="save">
        <Card :title="t('support.liveChat.settings.status')">
            <div class="grid gap-4">
                <Checkbox v-model="form.enabled" :label="t('support.liveChat.settings.enabled')" :description="t('support.liveChat.settings.enabledHint')" />
                <Textarea
                    v-model="form.origins"
                    :label="t('support.liveChat.settings.origins')"
                    :hint="insecureLocal ? `${t('support.liveChat.settings.originsHint', { max: maxOrigins })} ${t('support.liveChat.settings.originsLocalHint')}` : t('support.liveChat.settings.originsHint', { max: maxOrigins })"
                    :error="originsError"
                    :rows="4"
                    spellcheck="false"
                    autocapitalize="off"
                    control-class="font-mono"
                />
            </div>
        </Card>

        <Card :title="t('support.liveChat.settings.appearance')" :description="t('support.liveChat.settings.privacy')">
            <div class="@container grid gap-4">
                <Input v-model="form.widget_title" :label="t('support.liveChat.settings.widgetTitle')" :hint="t('support.liveChat.settings.widgetTitleHint')" :error="form.errors.widget_title" maxlength="60" optional />
                <div class="grid content-start gap-4 @lg:grid-cols-2">
                    <Textarea v-model="form.greeting" :label="t('support.liveChat.settings.greeting')" :error="form.errors.greeting" :rows="2" maxlength="300" optional />
                    <Textarea v-model="form.offline_note" :label="t('support.liveChat.settings.offlineNote')" :hint="t('support.liveChat.settings.offlineNoteHint')" :error="form.errors.offline_note" :rows="2" maxlength="300" optional />
                </div>
                <Select v-model="form.visitor_details" class="max-w-sm" :options="detailOptions" :label="t('support.liveChat.settings.visitorDetails')" :error="form.errors.visitor_details" />
            </div>
        </Card>

        <Card class="lg:col-span-2 xl:col-span-1" :title="t('support.liveChat.settings.embed')" :description="t('support.liveChat.settings.embedHint')">
            <div class="grid gap-3">
                <pre
                    class="overflow-x-auto rounded-control border border-line bg-hover px-3 py-2.5 font-mono text-[0.8125rem] whitespace-pre-wrap wrap-anywhere text-ink"
                    :aria-label="t('support.liveChat.settings.snippetLabel')"
                    tabindex="0"
                >{{ snippet }}</pre>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                    <p class="min-w-0 text-[0.8125rem] wrap-break-word text-ink-subtle">{{ t('support.liveChat.settings.embedOff') }}</p>
                    <Button size="sm" @click="copy">
                        <template #icon><Check v-if="copied" class="size-4" aria-hidden="true" /><Copy v-else class="size-4" aria-hidden="true" /></template>
                        {{ copied ? t('support.common.copied') : t('support.common.copy') }}
                    </Button>
                </div>
            </div>
        </Card>

        <!-- One save for the whole widget. -->
        <div class="flex justify-end lg:col-span-2 xl:col-span-3">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </div>
    </form>
</template>

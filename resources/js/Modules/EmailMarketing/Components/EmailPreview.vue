<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { route } from '@/Core/lib/route';
import { sendJson } from '@/Modules/Automation/lib/http';
import type { Block, PreviewResult } from './types';

/**
 * A live preview of the email with made-up recipient data. The HTML version is shown in a fully sandboxed
 * frame (no scripts, no forms, no navigation) and the plain-text version, which is what the platform mailer
 * sends today, next to it.
 */
const props = defineProps<{
    blocks: Block[];
    subject: string;
    preheader?: string | null;
}>();

const { t } = useI18n();

const result = ref<PreviewResult | null>(null);
const failed = ref(false);
const loading = ref(false);
const tab = ref<'html' | 'text'>('html');
let timer: ReturnType<typeof setTimeout> | null = null;
let sequence = 0;

async function refresh(): Promise<void> {
    const mine = ++sequence;
    loading.value = true;
    failed.value = false;

    const response = await sendJson<PreviewResult>('POST', route('email_marketing.content.preview'), {
        subject: props.subject,
        preheader: props.preheader ?? null,
        blocks: props.blocks,
    });

    if (mine !== sequence) {
        return;
    }

    loading.value = false;

    if (response.ok && response.data) {
        result.value = response.data;
    } else {
        result.value = null;
        failed.value = true;
    }
}

function schedule(): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void refresh(), 600);
}

watch(() => [props.blocks, props.subject, props.preheader], schedule, { deep: true });

onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

defineExpose({ refresh });
</script>

<template>
    <div data-testid="email-preview">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-control bg-hover p-1" role="group" :aria-label="t('emailMarketing.preview.title')">
                <Button size="sm" :variant="tab === 'html' ? 'secondary' : 'ghost'" :aria-pressed="tab === 'html'" @click="tab = 'html'">
                    {{ t('emailMarketing.preview.html') }}
                </Button>
                <Button size="sm" :variant="tab === 'text' ? 'secondary' : 'ghost'" :aria-pressed="tab === 'text'" @click="tab = 'text'">
                    {{ t('emailMarketing.preview.text') }}
                </Button>
            </div>
            <Button size="sm" :loading="loading" @click="refresh">{{ t('emailMarketing.preview.refresh') }}</Button>
        </div>

        <p v-if="failed" class="text-sm text-danger" role="alert">{{ t('emailMarketing.preview.failed') }}</p>
        <p v-else-if="result === null" class="text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.preview.idle') }}</p>
        <ul v-else-if="!result.valid" class="grid gap-1 text-sm text-danger" role="alert">
            <li v-for="(messages, key) in result.errors" :key="key">{{ messages[0] }}</li>
        </ul>
        <template v-else>
            <p class="mb-2 text-[0.8125rem] text-ink-muted">
                <span class="eyebrow mr-2">{{ t('emailMarketing.preview.subject') }}</span>{{ result.subject }}
            </p>
            <iframe
                v-if="tab === 'html'"
                class="h-[28rem] w-full rounded-card border border-line bg-white"
                sandbox=""
                referrerpolicy="no-referrer"
                :title="t('emailMarketing.preview.frameTitle')"
                :srcdoc="result.html"
            />
            <pre v-else class="max-h-[28rem] overflow-auto rounded-card border border-line p-4 text-[0.8125rem] whitespace-pre-wrap text-ink">{{ result.text }}</pre>
            <p class="mt-2 text-[0.75rem] text-ink-subtle">{{ t('emailMarketing.preview.plainNote') }}</p>
        </template>
    </div>
</template>

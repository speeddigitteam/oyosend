<script setup lang="ts">
import { Check, Copy, Download } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from './Button.vue';

/**
 * Shows freshly generated two-factor recovery codes. The server sends them
 * exactly once (as Inertia flash data), so this is the person's only chance
 * to save them - hence copy and download.
 */
const props = defineProps<{ codes: string[] }>();

const { t } = useI18n();
const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard access can be denied; the codes stay visible to copy by hand.
    }
}

function download(): void {
    const blob = new Blob([props.codes.join('\n') + '\n'], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = 'oyosend-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div class="rounded-card border border-warn/40 bg-warn-soft p-5" role="status">
        <h3 class="font-sans text-sm font-semibold tracking-normal text-ink">{{ t('common.twoFactor.recoveryTitle') }}</h3>
        <p class="mt-1 text-[0.8125rem] text-ink-muted">{{ t('common.twoFactor.recoveryBody') }}</p>

        <ul class="glass-well mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-control p-4 font-mono text-sm text-ink">
            <li v-for="code in codes" :key="code" class="tabular select-all">{{ code }}</li>
        </ul>

        <div class="mt-4 flex flex-wrap gap-2">
            <Button size="sm" @click="copy">
                <template #icon>
                    <Check v-if="copied" class="size-3.5 text-ok" aria-hidden="true" />
                    <Copy v-else class="size-3.5" aria-hidden="true" />
                </template>
                {{ copied ? t('common.actions.copied') : t('common.actions.copy') }}
            </Button>
            <Button size="sm" @click="download">
                <template #icon><Download class="size-3.5" aria-hidden="true" /></template>
                {{ t('common.twoFactor.download') }}
            </Button>
        </div>
    </div>
</template>

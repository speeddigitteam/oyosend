<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * The scan-this step of two-factor enrolment: the QR code and, for people
 * who cannot scan, the same secret as text.
 *
 * `qrCode` is SVG markup generated on the server by bacon-qr-code from the
 * account's own secret. It contains no user-supplied content, which is why
 * rendering it with v-html is safe here. Never pass anything else to it.
 */
defineProps<{
    qrCode: string;
    secret: string;
}>();

const { t } = useI18n();

function grouped(secret: string): string {
    return secret.match(/.{1,4}/g)?.join(' ') ?? secret;
}
</script>

<template>
    <!-- Side by side only where the text column keeps a readable measure; it depends on the card, not the screen. -->
    <div class="@container">
        <div class="flex flex-col items-center gap-5 @md:flex-row @md:items-start">
            <!-- White in both themes: authenticator apps need a light quiet zone to scan. -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="shrink-0 rounded-card bg-white p-2.5 shadow-[0_1px_2px_rgb(0_0_0/0.1)] [&>svg]:block [&>svg]:size-40" v-html="qrCode" />

            <div class="min-w-0 text-center @md:text-left">
                <p class="text-sm text-ink">{{ t('common.twoFactor.scan') }}</p>
                <p class="mt-3 text-[0.8125rem] text-ink-subtle">{{ t('common.twoFactor.manual') }}</p>
                <p class="glass-well tabular mt-1.5 inline-block rounded-control px-3 py-2 font-mono text-[0.8125rem] text-balance text-ink select-all">
                    {{ grouped(secret) }}
                </p>
            </div>
        </div>
    </div>
</template>

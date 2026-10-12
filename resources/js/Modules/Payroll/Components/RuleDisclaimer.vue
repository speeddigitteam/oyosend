<script setup lang="ts">
import { Info } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

/**
 * Said wherever rule sets are shown or edited: the rates are the workspace's
 * own entries. Oyosend ships no tax or contribution law and gives no advice.
 */
const { t } = useI18n();
</script>

<template>
    <aside class="glass mb-4 flex items-start gap-3 rounded-card px-5 py-3" role="note" data-testid="rule-disclaimer">
        <Info class="mt-0.5 size-4 shrink-0 text-info" aria-hidden="true" />
        <p class="text-sm text-ink-muted">{{ t('payroll.rules.disclaimer') }}</p>
    </aside>
</template>

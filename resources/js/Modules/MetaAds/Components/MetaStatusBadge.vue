<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { KNOWN_STATUSES, statusTone } from './metaAdsFormat';

const props = defineProps<{ status: string | null | undefined }>();

const { t } = useI18n();

const label = computed(() => {
    const value = (props.status ?? '').toUpperCase();

    if (value === '') {
        return t('metaAds.status.unknown');
    }

    return KNOWN_STATUSES.includes(value) ? t(`metaAds.status.${value}`) : value;
});
</script>

<template>
    <Badge :tone="statusTone(status)" dot>{{ label }}</Badge>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import { templateStatusTone } from './whatsappFormat';

defineProps<{ status: string }>();

const { t } = useI18n();
</script>

<template>
    <Badge :tone="templateStatusTone(status)" dot>{{ t(`whatsapp.templateStatus.${status}`) }}</Badge>
</template>

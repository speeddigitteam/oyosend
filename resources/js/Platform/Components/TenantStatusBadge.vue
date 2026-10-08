<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';

/** Values of App\Core\Tenancy\Enums\TenantStatus. */
export type TenantStatus = 'pending' | 'active' | 'suspended';

/**
 * A workspace's status as a badge. A status this build has no label for is
 * shown as the server named it rather than hidden.
 */
const props = defineProps<{ status: string }>();

const { t, te } = useI18n();

const TONES: Record<string, BadgeTone> = {
    active: 'ok',
    pending: 'warn',
    suspended: 'danger',
};

const label = computed(() => {
    const key = `platform.tenants.status.${props.status}`;

    return te(key) || te(key, 'en') ? t(key) : props.status;
});
</script>

<template>
    <Badge :tone="TONES[status] ?? 'neutral'" dot>{{ label }}</Badge>
</template>

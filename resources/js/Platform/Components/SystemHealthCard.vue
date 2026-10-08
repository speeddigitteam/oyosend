<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';

/** One entry of App\Platform\Support\SystemHealth::checks(). */
export interface HealthCheck {
    key: string;
    ok: boolean;
    /** The name of what answered, or a shortened error when nothing did. May be empty. */
    detail: string;
    /** What the check counted, for the checks that count something. */
    count: number | null;
}

/**
 * The live service checks. A passing check stays quiet; a failing one gets a
 * tinted row and its detail at full strength - enough to be seen first, with
 * nothing flashing.
 */
const props = defineProps<{ checks: HealthCheck[] }>();

const { t, te } = useI18n();
const format = useFormat();

const refreshing = ref(false);

const failing = computed(() => props.checks.filter((check) => !check.ok).length);

const summary = computed(() => {
    if (failing.value === 0) {
        return t('platform.health.allPassing');
    }

    return t(failing.value === 1 ? 'platform.health.failingOne' : 'platform.health.failing', {
        count: format.number(failing.value),
        total: format.number(props.checks.length),
    });
});

/** A check this build has no label for is still listed, under its key. */
function label(key: string): string {
    const path = `platform.health.checks.${key}`;

    return te(path) || te(path, 'en') ? t(path) : key;
}

/** Message for the number a check counted; a check without one shows the bare number. */
const COUNTED: Record<string, string> = {
    queue: 'platform.health.pending',
    failed_jobs: 'platform.health.failed',
};

function detail(check: HealthCheck): string {
    const parts = [check.detail];

    if (check.count !== null) {
        const count = format.number(check.count);
        const message = COUNTED[check.key];

        parts.push(message ? t(message, { count }) : count);
    }

    return parts.filter((part) => part !== '').join(' · ');
}

// The checks run on the server with every request, so asking again is a reload of this one prop.
function refresh(): void {
    router.reload({
        only: ['health'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}
</script>

<template>
    <Card :title="t('platform.health.title')" flush>
        <template #actions>
            <span role="status">
                <Badge :tone="failing === 0 ? 'ok' : 'warn'" dot>{{ summary }}</Badge>
            </span>
            <Button size="sm" variant="ghost" icon :loading="refreshing" :title="t('platform.health.refresh')" @click="refresh">
                <template #icon><RefreshCw class="size-3.5" aria-hidden="true" /></template>
                {{ t('platform.health.refresh') }}
            </Button>
        </template>

        <ul>
            <li
                v-for="check in checks"
                :key="check.key"
                :class="['flex items-start gap-3 border-t border-line px-5 py-3', check.ok ? '' : 'bg-warn-soft']"
            >
                <span
                    :class="[
                        'flex size-6 shrink-0 items-center justify-center rounded-full',
                        check.ok ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn',
                    ]"
                    aria-hidden="true"
                >
                    <Check v-if="check.ok" class="size-3.5" :stroke-width="2.5" />
                    <TriangleAlert v-else class="size-3.5" />
                </span>

                <div class="flex min-w-0 flex-1 flex-col gap-x-6 gap-y-0.5 sm:flex-row sm:items-baseline sm:justify-between">
                    <p class="shrink-0 text-sm leading-6 font-medium text-ink">
                        {{ label(check.key) }}
                        <span class="sr-only">: {{ check.ok ? t('common.status.healthy') : t('common.status.failing') }}</span>
                    </p>
                    <p :class="['min-w-0 font-mono text-xs wrap-anywhere sm:text-right', check.ok ? 'text-ink-subtle' : 'text-ink']">
                        {{ detail(check) }}
                    </p>
                </div>
            </li>
        </ul>
    </Card>
</template>

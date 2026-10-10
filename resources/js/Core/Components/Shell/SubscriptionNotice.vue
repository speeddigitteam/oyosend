<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CalendarClock, Hourglass, Lock, TriangleAlert, X } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { isCurrentRoute, route } from '@/Core/lib/route';

type Tone = 'info' | 'warn' | 'danger';

interface Notice {
    /** Identifies this notice for "do not show again in this browser session". */
    key: string;
    tone: Tone;
    icon: Component;
    text: string;
    /** What the reader can do about it, when that depends on who they are. */
    advice?: string;
}

/**
 * Where the workspace stands with its plan, shown at the top of every page -
 * but only when that needs someone's attention: the workspace is locked, a
 * payment is overdue, the plan is set to end, or a trial is in its last week.
 * A healthy subscription, or none at all, shows nothing.
 *
 * Everyone sees it, because everyone is affected when a workspace locks. The
 * way to billing is offered only to people who can open it.
 */
const TRIAL_WARNING_DAYS = 7;
const DISMISSED_KEY = 'oyosend:subscription-notice';

const page = usePage();
const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();

const canOpenBilling = computed(() => can('core.billing.view'));

const notice = computed<Notice | null>(() => {
    const subscription = page.props.subscription;

    if (!subscription) {
        return null;
    }

    const plan = subscription.plan;

    if (subscription.locked) {
        return {
            key: 'locked',
            tone: 'danger',
            icon: Lock,
            text: t('workspace.notice.locked', { plan }),
            advice: t(canOpenBilling.value ? 'workspace.notice.lockedOwner' : 'workspace.notice.lockedMember'),
        };
    }

    if (subscription.status === 'past_due') {
        return {
            key: `past-due:${subscription.graceEndsAt}`,
            tone: 'warn',
            icon: TriangleAlert,
            text: subscription.graceEndsAt
                ? t('workspace.notice.pastDue', { plan, date: format.date(subscription.graceEndsAt, 'datetime') })
                : t('workspace.notice.pastDueUndated', { plan }),
            advice: canOpenBilling.value ? undefined : t('workspace.notice.tellOwner'),
        };
    }

    // A canceled trial ends with the trial; it has no paid period.
    const endsAt = subscription.cancelAtPeriodEnd ? (subscription.periodEnd ?? subscription.trialEndsAt) : null;

    if (endsAt) {
        return {
            key: `ending:${endsAt}`,
            tone: 'info',
            icon: CalendarClock,
            text: t('workspace.notice.ending', { plan, date: format.date(endsAt) }),
        };
    }

    if (subscription.status === 'trialing' && subscription.trialEndsAt) {
        const left = new Date(subscription.trialEndsAt).getTime() - Date.now();

        if (left <= TRIAL_WARNING_DAYS * 86_400_000) {
            return {
                key: `trial:${subscription.trialEndsAt}`,
                tone: 'info',
                icon: Hourglass,
                text:
                    left > 0
                        ? t('workspace.notice.trialEnding', { plan, date: format.date(subscription.trialEndsAt) })
                        : t('workspace.notice.trialEnded', { plan }),
            };
        }
    }

    return null;
});

/*
 * A reminder may be put away for the rest of the browser session; it is back
 * the next time. An overdue payment and a locked workspace cannot be put away.
 */
function storedDismissal(): string | null {
    try {
        return window.sessionStorage.getItem(DISMISSED_KEY);
    } catch {
        return null;
    }
}

const dismissedKey = ref<string | null>(storedDismissal());

const dismissible = computed(() => notice.value?.tone === 'info');
const visible = computed(() => notice.value !== null && !(dismissible.value && dismissedKey.value === notice.value.key));

function dismiss(): void {
    if (!notice.value) {
        return;
    }

    dismissedKey.value = notice.value.key;

    try {
        window.sessionStorage.setItem(DISMISSED_KEY, notice.value.key);
    } catch {
        // Private browsing without storage: put away until the next full page load.
    }
}

// Recomputed on every visit because it reads the current URL. On the billing pages the link would lead nowhere new.
const showBillingLink = computed(() => canOpenBilling.value && page.url !== undefined && !isCurrentRoute('core.billing.*'));

const CHIPS: Record<Tone, string> = {
    info: 'bg-info-soft text-info',
    warn: 'bg-warn-soft text-warn',
    danger: 'bg-danger-soft text-danger',
};
</script>

<template>
    <section
        v-if="notice && visible"
        :aria-label="t('workspace.notice.label')"
        class="glass mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-card py-2.5 pr-3 pl-3"
    >
        <span :class="['flex size-8 shrink-0 items-center justify-center rounded-full', CHIPS[notice.tone]]" aria-hidden="true">
            <component :is="notice.icon" class="size-4" />
        </span>

        <p class="min-w-0 flex-1 basis-56 text-sm text-ink">
            {{ notice.text }}
            <span v-if="notice.advice" class="text-ink-muted">{{ notice.advice }}</span>
        </p>

        <div v-if="showBillingLink || dismissible" class="ml-auto flex shrink-0 items-center gap-1">
            <Button v-if="showBillingLink" size="sm" :variant="notice.tone === 'info' ? 'secondary' : 'primary'" :href="route('core.billing.index')">
                {{ t('workspace.notice.billing') }}
            </Button>
            <Button v-if="dismissible" icon size="sm" variant="ghost" @click="dismiss">
                <template #icon><X class="size-4" aria-hidden="true" /></template>
                {{ t('common.dismiss') }}
            </Button>
        </div>
    </section>
</template>

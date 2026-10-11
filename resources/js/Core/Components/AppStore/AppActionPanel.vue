<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarClock, Check, CreditCard, PackageCheck, PackageMinus, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import { useFocusReturn } from '@/Core/Components/Team/focus';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import { useAppCatalog } from './catalog';
import { useAppStoreTerms } from './terms';
import type { AppOffer, StoreApp } from './types';

const props = defineProps<{ app: StoreApp }>();

const { t } = useI18n();
const { can } = useAccess();
const format = useFormat();
const terms = useAppStoreTerms();
const catalog = useAppCatalog();
const appName = computed(() => catalog.name(props.app));

const canManage = computed(() => can('core.apps.manage'));
const canManageBilling = computed(() => can('core.billing.manage'));
const canBuy = computed(() => canManage.value && canManageBilling.value);
const installed = computed(() => props.app.installation?.status !== 'uninstalled' && props.app.installation !== null);
const liveSubscription = computed(() =>
    props.app.subscription !== null && ['trialing', 'active', 'past_due'].includes(props.app.subscription.status),
);
const canActivate = computed(() => props.app.installation?.status === 'inactive' && props.app.licence?.status === 'active');
const includedOnly = computed(() => props.app.includedInPlan && props.app.installation === null && props.app.licence === null);
const canReinstall = computed(
    () =>
        props.app.installation?.status === 'uninstalled' &&
        (props.app.licence?.status === 'active' || props.app.includedInPlan),
);
const paidApp = computed(
    () => props.app.subscription !== null || ['purchase', 'trial'].includes(props.app.licence?.source ?? ''),
);
const canUninstall = computed(() => canManage.value && (!paidApp.value || canManageBilling.value));
const updateAvailable = computed(
    () =>
        props.app.installation !== null &&
        props.app.installation.status !== 'uninstalled' &&
        props.app.installation.installedVersion !== props.app.version,
);
const mayChooseOffer = computed(
    () =>
        (!installed.value || (props.app.installation?.status === 'inactive' && props.app.licence === null)) &&
        !includedOnly.value &&
        !liveSubscription.value,
);
const recurring = computed(() => ['monthly', 'yearly'].includes(props.app.subscription?.billingType ?? ''));
const subscriptionTone = computed<BadgeTone>(() => {
    const status = props.app.subscription?.status;

    if (status === 'past_due') {
        return 'warn';
    }

    return status === 'trialing' ? 'info' : 'ok';
});
const subscriptionStanding = computed(() => {
    const subscription = props.app.subscription;

    if (!subscription) {
        return '';
    }

    if (subscription.cancelAtPeriodEnd) {
        return t('appStore.subscription.ends', { date: format.date(subscription.periodEnd ?? subscription.trialEndsAt) });
    }

    if (subscription.status === 'trialing') {
        return t('appStore.subscription.trial', { date: format.date(subscription.trialEndsAt) });
    }

    if (subscription.status === 'past_due') {
        return t('appStore.subscription.pastDue', { date: format.date(subscription.graceEndsAt) });
    }

    return t('appStore.subscription.renews', { date: format.date(subscription.periodEnd) });
});

const processing = ref<string | null>(null);
const error = ref<string | null>(null);
const confirmation = ref<'purchase' | 'deactivate' | 'uninstall' | 'cancel' | null>(null);
const chosenOffer = ref<AppOffer | null>(null);
const confirmationOpen = computed({
    get: () => confirmation.value !== null,
    set: (open: boolean) => {
        if (!open) {
            confirmation.value = null;
        }
    },
});

useFocusReturn(confirmationOpen);

function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors)[0] ?? null;
}

function visit(method: 'post' | 'delete', url: string, key: string, data: Record<string, string> = {}, close = false): void {
    processing.value = key;
    error.value = null;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (close) {
                confirmation.value = null;
            }
        },
        onError: (errors: Record<string, string>) => {
            error.value = firstError(errors);
        },
        onFinish: () => {
            processing.value = null;
        },
    };

    if (method === 'delete') {
        router.delete(url, options);
    } else {
        router.post(url, data, options);
    }
}

function install(offer: AppOffer): void {
    visit('post', route('core.apps.install', { app: props.app.key }), `offer:${offer.key}`, { offer: offer.key });
}

function askPurchase(offer: AppOffer): void {
    chosenOffer.value = offer;
    error.value = null;
    confirmation.value = 'purchase';
}

function purchase(): void {
    if (!chosenOffer.value) {
        return;
    }

    visit(
        'post',
        route('core.apps.purchase', { app: props.app.key }),
        `offer:${chosenOffer.value.key}`,
        { offer: chosenOffer.value.key },
        true,
    );
}

function activate(): void {
    visit('post', route('core.apps.activate', { app: props.app.key }), 'activate');
}

function upgrade(): void {
    visit('post', route('core.apps.upgrade', { app: props.app.key }), 'upgrade');
}

function reinstall(): void {
    visit('post', route('core.apps.reinstall', { app: props.app.key }), 'reinstall');
}

function ask(kind: 'deactivate' | 'uninstall' | 'cancel'): void {
    error.value = null;
    confirmation.value = kind;
}

function confirmAction(): void {
    if (confirmation.value === 'purchase') {
        purchase();
    } else if (confirmation.value === 'deactivate') {
        visit('post', route('core.apps.deactivate', { app: props.app.key }), 'deactivate', {}, true);
    } else if (confirmation.value === 'uninstall') {
        visit('delete', route('core.apps.uninstall', { app: props.app.key }), 'uninstall', {}, true);
    } else if (confirmation.value === 'cancel' && props.app.subscription) {
        visit(
            'post',
            route('core.apps.subscriptions.cancel', { item: props.app.subscription.id }),
            'cancel',
            {},
            true,
        );
    }
}

function resume(): void {
    if (props.app.subscription) {
        visit('post', route('core.apps.subscriptions.resume', { item: props.app.subscription.id }), 'resume');
    }
}

function price(offer: AppOffer): string {
    if (offer.type === 'free') {
        return t('appStore.terms.free');
    }

    if (offer.amount === null || offer.currency === null) {
        return terms.billingType(offer.type);
    }

    const amount = format.money(offer.amount, offer.currency);

    return offer.type === 'monthly' || offer.type === 'yearly'
        ? t(`appStore.terms.price.${offer.type}`, { amount })
        : amount;
}

const confirmationCopy = computed(() => {
    if (confirmation.value === 'purchase' && chosenOffer.value) {
        return {
            title: t('appStore.confirm.purchase.title', { app: appName.value }),
            description: t('appStore.confirm.purchase.body', {
                offer: chosenOffer.value.name,
                price: price(chosenOffer.value),
            }),
            label: t('appStore.confirm.purchase.submit'),
            tone: 'default' as const,
        };
    }

    if (confirmation.value === 'deactivate') {
        return {
            title: t('appStore.confirm.deactivate.title', { app: appName.value }),
            description: t('appStore.confirm.deactivate.body'),
            label: t('appStore.confirm.deactivate.submit'),
            tone: 'default' as const,
        };
    }

    if (confirmation.value === 'cancel') {
        return {
            title: t('appStore.confirm.cancel.title', { app: appName.value }),
            description: t('appStore.confirm.cancel.body', {
                date: format.date(props.app.subscription?.periodEnd ?? props.app.subscription?.trialEndsAt),
            }),
            label: t('appStore.confirm.cancel.submit'),
            tone: 'danger' as const,
        };
    }

    return {
        title: t('appStore.confirm.uninstall.title', { app: appName.value }),
        description: t('appStore.confirm.uninstall.body'),
        label: t('appStore.confirm.uninstall.submit'),
        tone: 'danger' as const,
    };
});
</script>

<template>
    <Card :title="t('appStore.actions.title')" :description="t('appStore.actions.description')">
        <div v-if="updateAvailable" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-control bg-brand-soft p-4">
            <div>
                <p class="font-medium text-brand-text">{{ t('appStore.actions.update.title', { version: app.version }) }}</p>
                <p class="mt-0.5 text-[0.8125rem] text-ink-muted">
                    {{ t('appStore.actions.update.body', { version: app.installation?.installedVersion ?? '—' }) }}
                </p>
            </div>
            <Button v-if="canManage" variant="primary" :loading="processing === 'upgrade'" @click="upgrade">
                {{ t('appStore.actions.update.submit') }}
            </Button>
            <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('appStore.actions.update.viewOnly') }}</p>
        </div>

        <div v-if="app.installation?.status === 'pending_payment'" class="flex items-start gap-3 rounded-control bg-warn-soft p-4 text-warn">
            <CreditCard class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">{{ t('appStore.actions.pending.title') }}</p>
                <p class="mt-0.5 text-[0.8125rem]">{{ t('appStore.actions.pending.body') }}</p>
            </div>
        </div>

        <div v-else-if="includedOnly" class="flex items-start gap-3 rounded-control bg-ok-soft p-4 text-ok">
            <PackageCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">{{ t('appStore.actions.included.title') }}</p>
                <p class="mt-0.5 text-[0.8125rem]">{{ t('appStore.actions.included.body') }}</p>
            </div>
        </div>

        <div v-else-if="canReinstall" class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-ok-soft p-4">
            <div class="flex min-w-0 items-start gap-3 text-ok">
                <RotateCcw class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div>
                    <p class="font-medium">{{ t('appStore.actions.reinstall.title') }}</p>
                    <p class="mt-0.5 text-[0.8125rem]">{{ t('appStore.actions.reinstall.body') }}</p>
                </div>
            </div>
            <Button v-if="canManage" variant="primary" :loading="processing === 'reinstall'" @click="reinstall">
                {{ t('appStore.actions.reinstall.submit') }}
            </Button>
            <p v-else class="text-[0.8125rem] text-ink-subtle">{{ t('appStore.actions.reinstall.viewOnly') }}</p>
        </div>

        <div v-else-if="app.installation?.status === 'active'" class="flex flex-wrap gap-2">
            <Button v-if="canManage" :loading="processing === 'deactivate'" @click="ask('deactivate')">
                <template #icon><PackageMinus class="size-4" aria-hidden="true" /></template>
                {{ t('appStore.actions.deactivate') }}
            </Button>
            <Button
                v-if="canManage"
                variant="danger"
                :disabled="!canUninstall"
                :loading="processing === 'uninstall'"
                @click="ask('uninstall')"
            >
                {{ t('appStore.actions.uninstall') }}
            </Button>
            <p v-if="canManage && !canUninstall" class="basis-full text-xs text-ink-subtle">
                {{ t('appStore.actions.paidControlPermission') }}
            </p>
            <p v-if="!canManage" class="text-[0.8125rem] text-ink-subtle">{{ t('appStore.actions.viewOnly') }}</p>
        </div>

        <div v-else-if="canActivate" class="flex flex-wrap gap-2">
            <Button v-if="canManage" variant="primary" :loading="processing === 'activate'" @click="activate">
                <template #icon><PackageCheck class="size-4" aria-hidden="true" /></template>
                {{ t('appStore.actions.activate') }}
            </Button>
            <Button
                v-if="canManage"
                variant="danger"
                :disabled="!canUninstall"
                :loading="processing === 'uninstall'"
                @click="ask('uninstall')"
            >
                {{ t('appStore.actions.uninstall') }}
            </Button>
            <p v-if="canManage && !canUninstall" class="basis-full text-xs text-ink-subtle">
                {{ t('appStore.actions.paidControlPermission') }}
            </p>
            <p v-if="!canManage" class="text-[0.8125rem] text-ink-subtle">{{ t('appStore.actions.viewOnly') }}</p>
        </div>

        <div v-else-if="mayChooseOffer" class="grid gap-3 sm:grid-cols-2">
            <div v-for="offer in app.offers" :key="offer.key" class="flex flex-col rounded-control border border-line p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-ink">{{ offer.name }}</p>
                        <p class="mt-0.5 text-xs text-ink-subtle">{{ terms.billingType(offer.type) }}</p>
                    </div>
                    <Badge :tone="offer.type === 'free' ? 'ok' : 'brand'">{{ price(offer) }}</Badge>
                </div>
                <p v-if="offer.trial_days > 0" class="mt-3 flex items-center gap-1.5 text-[0.8125rem] text-ink-muted">
                    <CalendarClock class="size-4" aria-hidden="true" />
                    {{ t('appStore.terms.trialDays', { count: format.number(offer.trial_days) }) }}
                </p>
                <div class="mt-auto pt-4">
                    <Button
                        v-if="offer.type === 'free' && canManage"
                        variant="primary"
                        block
                        :loading="processing === `offer:${offer.key}`"
                        @click="install(offer)"
                    >
                        {{ t('appStore.actions.install') }}
                    </Button>
                    <Button
                        v-else-if="['monthly', 'yearly', 'one_time'].includes(offer.type) && canManage"
                        variant="primary"
                        block
                        :disabled="!canBuy"
                        :loading="processing === `offer:${offer.key}`"
                        @click="askPurchase(offer)"
                    >
                        {{ offer.trial_days > 0 ? t('appStore.actions.startTrial') : t('appStore.actions.buy') }}
                    </Button>
                    <p v-else-if="offer.type === 'enterprise' || offer.type === 'usage_based'" class="text-[0.8125rem] text-ink-subtle">
                        {{ t('appStore.actions.contactSupport') }}
                    </p>
                    <p v-else-if="!canManage" class="text-[0.8125rem] text-ink-subtle">{{ t('appStore.actions.viewOnly') }}</p>
                    <p v-if="canManage && !canBuy && offer.type !== 'free'" class="mt-2 text-xs text-ink-subtle">
                        {{ t('appStore.actions.billingPermission') }}
                    </p>
                </div>
            </div>
            <div v-if="app.installation?.status === 'inactive' && canManage" class="sm:col-span-2">
                <Button
                    variant="danger"
                    :disabled="!canUninstall"
                    :loading="processing === 'uninstall'"
                    @click="ask('uninstall')"
                >
                    {{ t('appStore.actions.uninstallExpired') }}
                </Button>
                <p v-if="!canUninstall" class="mt-2 text-xs text-ink-subtle">
                    {{ t('appStore.actions.paidControlPermission') }}
                </p>
            </div>
        </div>

        <div v-else-if="app.installation?.status === 'uninstalled'" class="rounded-control bg-hover p-4 text-[0.8125rem] text-ink-muted">
            {{ t('appStore.actions.uninstalledEnding') }}
        </div>

        <div
            v-if="app.subscription && recurring && liveSubscription && app.installation?.status !== 'uninstalled'"
            class="mt-4 border-t border-line pt-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium text-ink">{{ t('appStore.subscription.title') }}</p>
                        <Badge :tone="subscriptionTone" dot>{{ terms.subscriptionStatus(app.subscription.status) }}</Badge>
                    </div>
                    <p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ subscriptionStanding }}</p>
                </div>
                <Button
                    v-if="canManage && app.subscription.cancelAtPeriodEnd"
                    :disabled="!canManageBilling"
                    :loading="processing === 'resume'"
                    @click="resume"
                >
                    <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                    {{ t('appStore.actions.resume') }}
                </Button>
                <Button
                    v-else-if="canManage"
                    variant="ghost"
                    :disabled="!canManageBilling"
                    :loading="processing === 'cancel'"
                    @click="ask('cancel')"
                >
                    {{ t('appStore.actions.cancelSubscription') }}
                </Button>
            </div>
            <p v-if="canManage && !canManageBilling" class="mt-2 text-xs text-ink-subtle">
                {{ t('appStore.actions.paidControlPermission') }}
            </p>
        </div>

        <p v-if="error && confirmation === null" class="mt-4 text-[0.8125rem] text-danger" role="alert">{{ error }}</p>

        <ConfirmDialog
            v-model:open="confirmationOpen"
            :title="confirmationCopy.title"
            :description="confirmationCopy.description"
            :confirm-label="confirmationCopy.label"
            :tone="confirmationCopy.tone"
            :loading="processing !== null"
            @confirm="confirmAction"
        >
            <p v-if="error" class="text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
            <p v-if="confirmation === 'purchase' && chosenOffer?.trial_days" class="flex items-center gap-2 text-[0.8125rem] text-ink-muted">
                <Check class="size-4 text-ok" aria-hidden="true" />
                {{ t('appStore.confirm.purchase.trial', { count: format.number(chosenOffer.trial_days) }) }}
            </p>
        </ConfirmDialog>
    </Card>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Delete, Mic, MicOff, Phone, PhoneForwarded, PhoneIncoming, PhoneMissed, PhoneOff, PhoneOutgoing, RefreshCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import TelephonyNav from '../Components/TelephonyNav.vue';
import { statusTone, type CallRow } from '../Components/types';
import { requestJson } from '../lib/http';
import { displayPhone, formatDuration, KEYPAD, normalizePhone } from '../lib/phone';
import { useSoftphone } from '../lib/softphone';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    setup: {
        workspaceSoftphone: boolean;
        workspaceBridge: boolean;
        extension: string | null;
        hasPassword: boolean;
        bridgePhone: string | null;
    };
    prefill: { number: string | null; contact: { id: string; name: string } | null };
    recent: CallRow[];
    canViewLog: boolean;
    canManageSettings: boolean;
}>();

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();

const audio = ref<HTMLAudioElement | null>(null);
const softphone = useSoftphone(
    {
        credentials: route('telephony.softphone.credentials'),
        start: route('telephony.calls.start'),
        report: (id: string) => route('telephony.calls.report', { call: id }),
    },
    audio,
);

const number = ref(props.prefill.number ? displayPhone(props.prefill.number) : '');
const error = ref<string | null>(null);
const bridging = ref(false);

/** The softphone can be used: the workspace's PBX has a WebSocket and the member an extension with a password. */
const softphoneAvailable = computed(() => props.setup.workspaceSoftphone && props.setup.extension !== null && props.setup.hasPassword);
/** Calls through the member's own phone can be started. */
const bridgeAvailable = computed(() => props.setup.workspaceBridge && props.setup.extension !== null);
const nothingAvailable = computed(() => !softphoneAvailable.value && !bridgeAvailable.value);

const missingReason = computed(() => {
    if (!props.setup.workspaceSoftphone && !props.setup.workspaceBridge) return t('telephony.dialer.notConfigured.workspace');
    if (props.setup.extension === null) return t('telephony.dialer.notConfigured.extension');

    return t('telephony.dialer.notConfigured.password');
});

const phoneTone = computed(() => ({ ready: 'ok', connecting: 'info', offline: 'warn', unconfigured: 'neutral' })[softphone.phone.value] as 'ok' | 'info' | 'warn' | 'neutral');

const bridgeHint = computed(() =>
    props.setup.bridgePhone !== null
        ? t('telephony.dialer.bridgeHintPhone', { phone: displayPhone(props.setup.bridgePhone) })
        : t('telephony.dialer.bridgeHintExtension', { extension: props.setup.extension ?? '' }),
);

// The elapsed time of a connected call, refreshed every second while one is up.
const now = ref(Date.now());
let ticker: number | undefined;

watch(
    () => softphone.connectedAt.value,
    (startedAt) => {
        window.clearInterval(ticker);

        if (startedAt !== null) {
            now.value = Date.now();
            ticker = window.setInterval(() => (now.value = Date.now()), 1000);
        }
    },
);

const elapsed = computed(() => (softphone.connectedAt.value === null ? null : formatDuration((now.value - softphone.connectedAt.value) / 1000)));
const remoteLabel = computed(() => (softphone.remote.value ? displayPhone(normalizePhone(softphone.remote.value) ?? softphone.remote.value) : ''));

onMounted(() => {
    if (softphoneAvailable.value) void softphone.connect();
});

onBeforeUnmount(() => window.clearInterval(ticker));

function press(key: string): void {
    error.value = null;

    if (softphone.call.value === 'connected') {
        softphone.sendTone(key);

        return;
    }

    number.value += key;
}

function backspace(): void {
    number.value = number.value.slice(0, -1);
}

function target(): string | null {
    const normalized = normalizePhone(number.value);

    if (normalized === null) {
        error.value = t('telephony.dialer.invalid');

        return null;
    }

    error.value = null;

    return normalized;
}

function callSoftphone(): void {
    const e164 = target();

    if (e164 !== null) softphone.dial(e164);
}

async function callBridge(): Promise<void> {
    const e164 = target();

    if (e164 === null || bridging.value) return;

    bridging.value = true;
    const response = await requestJson<{ status: string; message?: string }>('POST', route('telephony.bridge'), { number: e164 });
    bridging.value = false;

    if (response.ok) toasts.success(t('telephony.dialer.bridgeStarted'));
    else if (response.status === 422) error.value = response.message ?? t('telephony.dialer.invalid');
    else toasts.error(response.message ?? t('telephony.dialer.bridgeFailed'));
}

function redial(row: CallRow): void {
    number.value = displayPhone(row.number);
    error.value = null;
}

const directionIcon = (row: CallRow) => (row.status === 'missed' ? PhoneMissed : row.direction === 'inbound' ? PhoneIncoming : PhoneOutgoing);
</script>

<template>
    <PageHeader :eyebrow="t('telephony.eyebrow')" :title="t('telephony.dialer.title')" :description="t('telephony.dialer.description')" />
    <TelephonyNav current="dialer" />

    <div class="grid items-start gap-3 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <Card :title="t('telephony.dialer.phone')">
            <template v-if="softphoneAvailable" #actions>
                <Badge :tone="phoneTone" dot data-testid="softphone-state">{{ t(`telephony.dialer.state.${softphone.phone.value}`) }}</Badge>
            </template>

            <EmptyState v-if="nothingAvailable" :title="t('telephony.dialer.notConfigured.title')" :description="`${missingReason} ${t('telephony.dialer.notConfigured.ask')}`" compact data-testid="dialer-not-configured">
                <template #icon><PhoneOff /></template>
                <Button v-if="canManageSettings" size="sm" variant="secondary" :href="route('telephony.settings.index')">{{ t('telephony.dialer.notConfigured.settings') }}</Button>
            </EmptyState>

            <template v-else>
                <!-- A call in progress, ringing in, or being placed. -->
                <div v-if="softphone.call.value !== 'idle'" class="grid gap-4 text-center" data-testid="active-call" aria-live="polite">
                    <div>
                        <p class="text-sm text-ink-muted">{{ t(`telephony.dialer.active.${softphone.call.value}`) }}</p>
                        <p class="tabular mt-1 font-mono text-2xl text-ink">{{ remoteLabel }}</p>
                        <p v-if="elapsed" class="tabular mt-1 text-sm text-ink-muted">{{ elapsed }}</p>
                    </div>

                    <div v-if="softphone.call.value === 'incoming'" class="flex justify-center gap-3">
                        <Button variant="primary" @click="softphone.answer()">
                            <template #icon><Phone class="size-4" aria-hidden="true" /></template>
                            {{ t('telephony.dialer.answer') }}
                        </Button>
                        <Button variant="danger" @click="softphone.decline()">
                            <template #icon><PhoneOff class="size-4" aria-hidden="true" /></template>
                            {{ t('telephony.dialer.decline') }}
                        </Button>
                    </div>

                    <template v-else>
                        <div v-if="softphone.call.value === 'connected'" class="mx-auto grid w-full max-w-64 grid-cols-3 gap-2" role="group" :aria-label="t('telephony.dialer.keypad')">
                            <button v-for="key in KEYPAD" :key="key.key" type="button" class="h-11 rounded-control border border-line-strong text-lg font-medium text-ink transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" @click="press(key.key)">
                                {{ key.key }}
                            </button>
                        </div>
                        <div class="flex justify-center gap-3">
                            <Button v-if="softphone.call.value === 'connected'" :aria-pressed="softphone.muted.value" @click="softphone.toggleMute()">
                                <template #icon><component :is="softphone.muted.value ? MicOff : Mic" class="size-4" aria-hidden="true" /></template>
                                {{ softphone.muted.value ? t('telephony.dialer.unmute') : t('telephony.dialer.mute') }}
                            </Button>
                            <Button variant="danger" @click="softphone.hangUp()">
                                <template #icon><PhoneOff class="size-4" aria-hidden="true" /></template>
                                {{ t('telephony.dialer.hangUp') }}
                            </Button>
                        </div>
                    </template>
                </div>

                <!-- Idle: the number and the keypad. -->
                <form v-else class="grid gap-4" @submit.prevent="softphoneAvailable ? callSoftphone() : callBridge()">
                    <p v-if="prefill.contact" class="text-sm text-ink-muted">{{ t('telephony.dialer.contactFor', { name: prefill.contact.name }) }}</p>
                    <Input
                        v-model="number"
                        type="tel"
                        inputmode="tel"
                        autocomplete="off"
                        maxlength="40"
                        mono
                        :label="t('telephony.dialer.number')"
                        :placeholder="t('telephony.dialer.numberPlaceholder')"
                        :error="error ?? undefined"
                    >
                        <template v-if="number !== ''" #trailing>
                            <Button variant="ghost" size="sm" icon @click="backspace">
                                <template #icon><Delete class="size-4" aria-hidden="true" /></template>
                                {{ t('telephony.dialer.backspace') }}
                            </Button>
                        </template>
                    </Input>

                    <div class="mx-auto grid w-full max-w-72 grid-cols-3 gap-2" role="group" :aria-label="t('telephony.dialer.keypad')">
                        <button
                            v-for="key in KEYPAD"
                            :key="key.key"
                            type="button"
                            class="flex h-14 flex-col items-center justify-center rounded-control border border-line-strong text-ink transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            :data-key="key.key"
                            @click="press(key.key)"
                        >
                            <span class="text-xl leading-none font-medium">{{ key.key }}</span>
                            <span v-if="key.letters" class="mt-0.5 text-[0.625rem] leading-none tracking-widest text-ink-subtle" aria-hidden="true">{{ key.letters }}</span>
                        </button>
                    </div>

                    <div class="grid gap-2">
                        <Button v-if="softphoneAvailable" type="submit" variant="primary" block :disabled="softphone.phone.value !== 'ready'" data-testid="call-softphone">
                            <template #icon><Phone class="size-4" aria-hidden="true" /></template>
                            {{ t('telephony.dialer.call') }}
                        </Button>
                        <Button v-if="bridgeAvailable" :variant="softphoneAvailable ? 'secondary' : 'primary'" block :loading="bridging" data-testid="call-bridge" @click="callBridge">
                            <template #icon><PhoneForwarded class="size-4" aria-hidden="true" /></template>
                            {{ t('telephony.dialer.bridge') }}
                        </Button>
                        <p v-if="bridgeAvailable" class="text-center text-xs text-ink-subtle">{{ bridgeHint }}</p>
                    </div>
                </form>

                <p v-if="softphone.problem.value" class="mt-4 text-sm text-danger" role="alert">{{ t(`telephony.dialer.problems.${softphone.problem.value}`) }}</p>
                <div v-if="softphoneAvailable && softphone.phone.value === 'offline' && softphone.call.value === 'idle'" class="mt-3 flex justify-center">
                    <Button size="sm" variant="ghost" @click="softphone.connect()">
                        <template #icon><RefreshCw class="size-4" aria-hidden="true" /></template>
                        {{ t('telephony.dialer.reconnect') }}
                    </Button>
                </div>
            </template>

            <!-- The other side of a softphone call plays here. -->
            <audio ref="audio" autoplay class="hidden" />
        </Card>

        <Card :title="t('telephony.dialer.recent')" flush>
            <template v-if="canViewLog" #actions>
                <Link :href="route('telephony.calls.index')" class="text-sm font-medium text-brand-text hover:underline">{{ t('telephony.dialer.viewAll') }}</Link>
            </template>
            <EmptyState v-if="recent.length === 0" :title="t('telephony.dialer.recentEmpty')" :description="t('telephony.dialer.recentEmptyBody')" compact>
                <template #icon><Phone /></template>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in recent" :key="row.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand"
                        :aria-label="t('telephony.dialer.redial', { number: displayPhone(row.number) })"
                        @click="redial(row)"
                    >
                        <component :is="directionIcon(row)" :class="['size-4 shrink-0', row.status === 'missed' ? 'text-warn' : 'text-ink-subtle']" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink">{{ row.contact?.name ?? displayPhone(row.number) }}</span>
                            <span class="block truncate text-xs text-ink-subtle">
                                <template v-if="row.contact">{{ displayPhone(row.number) }} · </template>{{ format.date(row.startedAt, 'datetime') }}
                            </span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            <Badge :tone="statusTone(row.status)">{{ t(`telephony.statuses.${row.status}`) }}</Badge>
                            <span v-if="row.durationSeconds" class="tabular text-xs text-ink-subtle">{{ formatDuration(row.durationSeconds) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </Card>
    </div>
</template>

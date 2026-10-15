<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Copy, KeyRound, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import PasswordInput from '@/Core/Components/PasswordInput.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import TelephonyNav from '../../Components/TelephonyNav.vue';
import { displayPhone } from '../../lib/phone';

defineOptions({ layout: TenantLayout });

interface MemberRow {
    id: string;
    name: string;
    email: string;
    extension: { id: string; extension: string; hasPassword: boolean; bridgePhone: string | null } | null;
}

const props = defineProps<{
    settings: {
        driver: string;
        wssUrl: string | null;
        sipDomain: string | null;
        apiUrl: string | null;
        apiUsername: string | null;
        hasApiSecret: boolean;
        originationContext: string | null;
        callerId: string | null;
        stunUrls: string[];
        turnUrl: string | null;
        turnUsername: string | null;
        hasTurnCredential: boolean;
        hasWebhookSecret: boolean;
        softphoneReady: boolean;
        bridgeReady: boolean;
    };
    drivers: string[];
    webhookUrl: string;
    /** Only on the visit right after a new secret was created. */
    revealedWebhookSecret: string | null;
    members: MemberRow[];
}>();

const { t } = useI18n();

// ---- the phone system --------------------------------------------------------------------------------------------
const form = useForm({
    driver: props.settings.driver,
    wss_url: props.settings.wssUrl ?? '',
    sip_domain: props.settings.sipDomain ?? '',
    stun_text: props.settings.stunUrls.join('\n'),
    turn_url: props.settings.turnUrl ?? '',
    turn_username: props.settings.turnUsername ?? '',
    turn_credential: '',
    api_url: props.settings.apiUrl ?? '',
    api_username: props.settings.apiUsername ?? '',
    api_secret: '',
    origination_context: props.settings.originationContext ?? '',
    caller_id: props.settings.callerId ?? '',
});

const driverOptions = computed(() => props.drivers.map((value) => ({ value, label: t(`telephony.settings.drivers.${value}`) })));
const nullable = (value: string): string | null => (value.trim() === '' ? null : value.trim());

function save(): void {
    form.transform((data) => ({
        driver: data.driver,
        wss_url: nullable(data.wss_url),
        sip_domain: nullable(data.sip_domain),
        stun_urls: data.stun_text.split('\n').map((line) => line.trim()).filter((line) => line !== ''),
        turn_url: nullable(data.turn_url),
        turn_username: nullable(data.turn_username),
        turn_credential: nullable(data.turn_credential),
        api_url: nullable(data.api_url),
        api_username: nullable(data.api_username),
        api_secret: nullable(data.api_secret),
        origination_context: nullable(data.origination_context),
        caller_id: nullable(data.caller_id),
    }));
    form.put(route('telephony.settings.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('api_secret', 'turn_credential'),
    });
}

const stunError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('stun_urls'))?.[1]);

// ---- call events ---------------------------------------------------------------------------------------------------
const copied = ref<string | null>(null);

async function copy(value: string, key: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        window.setTimeout(() => (copied.value = null), 2000);
    } catch {
        copied.value = null;
    }
}

const rotateOpen = ref(false);
const rotating = ref(false);

function rotate(): void {
    rotating.value = true;
    router.post(route('telephony.settings.webhook-secret'), {}, {
        preserveScroll: true,
        onFinish: () => {
            rotating.value = false;
            rotateOpen.value = false;
        },
    });
}

// ---- extensions ----------------------------------------------------------------------------------------------------
const columns = computed<Column[]>(() => [
    { key: 'member', label: t('telephony.settings.member') },
    { key: 'extension', label: t('telephony.settings.extension') },
    { key: 'bridge', label: t('telephony.settings.bridgePhone'), hideable: true },
    { key: 'actions', label: t('telephony.settings.edit'), srOnly: true, align: 'right' },
]);

const editing = ref<MemberRow | null>(null);
const extensionOpen = ref(false);
const extensionForm = useForm({ extension: '', sip_password: '', bridge_phone: '' });

function openExtension(member: MemberRow): void {
    editing.value = member;
    extensionForm.clearErrors();
    extensionForm.extension = member.extension?.extension ?? '';
    extensionForm.sip_password = '';
    extensionForm.bridge_phone = member.extension?.bridgePhone ? displayPhone(member.extension.bridgePhone) : '';
    extensionOpen.value = true;
}

function saveExtension(): void {
    const member = editing.value;

    if (member === null) return;

    const options = { preserveScroll: true, onSuccess: () => (extensionOpen.value = false) };
    extensionForm.transform((data) => ({
        ...(member.extension === null ? { membership_id: member.id } : {}),
        extension: data.extension.trim(),
        sip_password: nullable(data.sip_password),
        bridge_phone: nullable(data.bridge_phone),
    }));

    if (member.extension === null) extensionForm.post(route('telephony.settings.extensions.store'), options);
    else extensionForm.put(route('telephony.settings.extensions.update', { extension: member.extension.id }), options);
}

const removing = ref<MemberRow | null>(null);
const removeOpen = ref(false);
const removeBusy = ref(false);

function askRemove(member: MemberRow): void {
    removing.value = member;
    removeOpen.value = true;
}

function remove(): void {
    const extension = removing.value?.extension;

    if (!extension) return;

    removeBusy.value = true;
    router.delete(route('telephony.settings.extensions.destroy', { extension: extension.id }), {
        preserveScroll: true,
        onFinish: () => {
            removeBusy.value = false;
            removeOpen.value = false;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('telephony.eyebrow')" :title="t('telephony.settings.title')" :description="t('telephony.settings.description')" />
    <TelephonyNav current="settings" />

    <form class="grid gap-3" @submit.prevent="save">
        <Card :title="t('telephony.settings.connection')" :description="t('telephony.settings.connectionHint')">
            <Select v-model="form.driver" :options="driverOptions" :label="t('telephony.settings.driver')" :error="form.errors.driver" class="sm:max-w-72" />
        </Card>

        <Card :title="t('telephony.settings.softphone')" :description="t('telephony.settings.softphoneHint')">
            <template #actions>
                <Badge :tone="settings.softphoneReady ? 'ok' : 'neutral'" dot>{{ settings.softphoneReady ? t('telephony.settings.ready') : t('telephony.settings.notReady') }}</Badge>
            </template>
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.wss_url" :label="t('telephony.settings.wssUrl')" placeholder="wss://pbx.example.com:8089/ws" :error="form.errors.wss_url" maxlength="255" mono optional />
                <Input v-model="form.sip_domain" :label="t('telephony.settings.sipDomain')" placeholder="pbx.example.com" :error="form.errors.sip_domain" maxlength="190" mono optional />
            </div>
            <h3 class="mt-5 text-sm font-semibold text-ink">{{ t('telephony.settings.ice') }}</h3>
            <p class="mt-1 text-sm text-ink-muted">{{ t('telephony.settings.iceHint') }}</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <Textarea v-model="form.stun_text" :label="t('telephony.settings.stunUrls')" :hint="t('telephony.settings.stunHint')" :error="stunError" :rows="2" class="sm:col-span-2" optional />
                <Input v-model="form.turn_url" :label="t('telephony.settings.turnUrl')" placeholder="turn:turn.example.com:3478" :error="form.errors.turn_url" maxlength="255" mono optional />
                <Input v-model="form.turn_username" :label="t('telephony.settings.turnUsername')" :error="form.errors.turn_username" maxlength="120" autocomplete="off" optional />
                <PasswordInput
                    v-model="form.turn_credential"
                    :label="t('telephony.settings.turnCredential')"
                    :hint="settings.hasTurnCredential ? t('telephony.settings.secretStored') : t('telephony.settings.secretMissing')"
                    :error="form.errors.turn_credential"
                    autocomplete="new-password"
                    maxlength="255"
                />
            </div>
        </Card>

        <Card :title="t('telephony.settings.bridge')" :description="t('telephony.settings.bridgeHint')">
            <template #actions>
                <Badge :tone="settings.bridgeReady ? 'ok' : 'neutral'" dot>{{ settings.bridgeReady ? t('telephony.settings.ready') : t('telephony.settings.notReady') }}</Badge>
            </template>
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.api_url" :label="t('telephony.settings.apiUrl')" placeholder="https://pbx.example.com:8089" :error="form.errors.api_url" maxlength="255" mono optional class="sm:col-span-2" />
                <Input v-model="form.api_username" :label="t('telephony.settings.apiUsername')" :error="form.errors.api_username" maxlength="120" autocomplete="off" optional />
                <PasswordInput
                    v-model="form.api_secret"
                    :label="t('telephony.settings.apiSecret')"
                    :hint="settings.hasApiSecret ? t('telephony.settings.secretStored') : t('telephony.settings.secretMissing')"
                    :error="form.errors.api_secret"
                    autocomplete="new-password"
                    maxlength="255"
                />
                <Input v-model="form.origination_context" :label="t('telephony.settings.context')" placeholder="from-internal" :error="form.errors.origination_context" maxlength="80" mono optional />
                <Input v-model="form.caller_id" :label="t('telephony.settings.callerId')" :error="form.errors.caller_id" maxlength="40" mono optional />
            </div>
        </Card>

        <div class="flex justify-end">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('telephony.settings.save') }}</Button>
        </div>
    </form>

    <Card class="mt-3" :title="t('telephony.settings.webhook')" :description="t('telephony.settings.webhookHint')">
        <div class="grid gap-4">
            <Input :model-value="webhookUrl" :label="t('telephony.settings.webhookUrl')" readonly mono>
                <template #trailing>
                    <Button variant="ghost" size="sm" icon @click="copy(webhookUrl, 'url')">
                        <template #icon><Copy class="size-4" aria-hidden="true" /></template>
                        {{ copied === 'url' ? t('telephony.settings.copied') : t('telephony.settings.copy') }}
                    </Button>
                </template>
            </Input>

            <div v-if="revealedWebhookSecret" class="grid gap-2" data-testid="revealed-secret">
                <Input :model-value="revealedWebhookSecret" :label="t('telephony.settings.webhookSecret')" readonly mono>
                    <template #trailing>
                        <Button variant="ghost" size="sm" icon @click="copy(revealedWebhookSecret, 'secret')">
                            <template #icon><Copy class="size-4" aria-hidden="true" /></template>
                            {{ copied === 'secret' ? t('telephony.settings.copied') : t('telephony.settings.copy') }}
                        </Button>
                    </template>
                </Input>
                <p class="text-sm text-warn" role="alert">{{ t('telephony.settings.secretShownOnce') }}</p>
            </div>
            <p v-else class="text-sm text-ink-muted">{{ settings.hasWebhookSecret ? t('telephony.settings.secretSet') : t('telephony.settings.secretNotSet') }}</p>

            <div>
                <Button size="sm" variant="secondary" @click="rotateOpen = true">
                    <template #icon><KeyRound class="size-4" aria-hidden="true" /></template>
                    {{ t('telephony.settings.rotate') }}
                </Button>
            </div>
        </div>
    </Card>

    <Card class="mt-3" :title="t('telephony.settings.extensions')" :description="t('telephony.settings.extensionsHint')" flush>
        <DataTable :columns="columns" :rows="members" row-key="id" :caption="t('telephony.settings.extensions')">
            <template #cell-member="{ row }">
                <span class="block font-medium text-ink">{{ row.name }}</span>
                <span class="block text-xs text-ink-subtle">{{ row.email }}</span>
            </template>
            <template #cell-extension="{ row }">
                <template v-if="row.extension">
                    <span class="font-mono text-ink">{{ row.extension.extension }}</span>
                    <Badge class="ml-2" :tone="row.extension.hasPassword ? 'ok' : 'warn'">{{ row.extension.hasPassword ? t('telephony.settings.passwordStored') : t('telephony.settings.passwordMissing') }}</Badge>
                </template>
                <span v-else class="text-ink-subtle">{{ t('telephony.settings.noExtension') }}</span>
            </template>
            <template #cell-bridge="{ row }">
                <span class="tabular font-mono text-sm">{{ row.extension?.bridgePhone ? displayPhone(row.extension.bridgePhone) : t('telephony.calls.none') }}</span>
            </template>
            <template #cell-actions="{ row }">
                <span class="flex justify-end gap-1">
                    <Button size="sm" variant="ghost" @click="openExtension(row)">
                        <template #icon><component :is="row.extension ? Pencil : Plus" class="size-4" aria-hidden="true" /></template>
                        {{ row.extension ? t('telephony.settings.edit') : t('telephony.settings.give') }}
                    </Button>
                    <Button v-if="row.extension" size="sm" variant="ghost" icon @click="askRemove(row)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('telephony.settings.remove') }}
                    </Button>
                </span>
            </template>
        </DataTable>
    </Card>

    <Modal v-model:open="extensionOpen" :title="editing?.extension ? t('telephony.settings.edit') : t('telephony.settings.give')" :description="editing?.name" :persistent="extensionForm.processing">
        <form id="extension-form" class="grid gap-4" @submit.prevent="saveExtension">
            <Input v-model="extensionForm.extension" :label="t('telephony.settings.extension')" :error="extensionForm.errors.extension" maxlength="32" mono required />
            <PasswordInput
                v-model="extensionForm.sip_password"
                :label="t('telephony.settings.sipPassword')"
                :hint="editing?.extension?.hasPassword ? t('telephony.settings.secretStored') : t('telephony.settings.secretMissing')"
                :error="extensionForm.errors.sip_password"
                autocomplete="new-password"
                maxlength="128"
            />
            <Input v-model="extensionForm.bridge_phone" type="tel" :label="t('telephony.settings.bridgePhone')" :hint="t('telephony.settings.bridgePhoneHint')" :error="extensionForm.errors.bridge_phone" maxlength="40" mono optional />
        </form>
        <template #footer>
            <Button variant="ghost" @click="extensionOpen = false">{{ t('telephony.settings.cancel') }}</Button>
            <Button type="submit" form="extension-form" variant="primary" :loading="extensionForm.processing">{{ t('telephony.settings.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        v-model:open="rotateOpen"
        :title="t('telephony.settings.rotateTitle')"
        :description="t('telephony.settings.rotateBody')"
        :confirm-label="t('telephony.settings.rotate')"
        :loading="rotating"
        @confirm="rotate"
    />

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('telephony.settings.removeTitle')"
        :description="t('telephony.settings.removeBody', { name: removing?.name ?? '' })"
        :confirm-label="t('telephony.settings.remove')"
        tone="danger"
        :loading="removeBusy"
        @confirm="remove"
    />
</template>

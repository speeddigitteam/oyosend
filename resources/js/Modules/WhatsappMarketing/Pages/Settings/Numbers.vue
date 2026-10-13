<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Phone, Star, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useAccess } from '@/Core/composables/useAccess';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { safeRoute } from '../../Components/links';
import type { ChannelInfo, WhatsappNumber } from '../../Components/types';
import WhatsappNav from '../../Components/WhatsappNav.vue';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    numbers: WhatsappNumber[];
    channel: ChannelInfo;
    guidance: { communicationSettingsRoute: string };
}>();

const { t } = useI18n();
const { can } = useAccess();

const connected = computed(() => props.channel.state === 'available');
const communicationHref = computed(() => (can('communication.settings.manage') ? safeRoute(props.guidance.communicationSettingsRoute) : null));

const add = useForm({ account_id: '', label: '', display_number: '', make_default: false });

// The registry reports row-level problems under the `number` key, which is not a form field.
const addErrors = computed(() => add.errors as Record<string, string | undefined>);

function submit(): void {
    add.post(route('whatsapp_marketing.numbers.store'), {
        preserveScroll: true,
        onSuccess: () => add.reset(),
    });
}

function toggleActive(number: WhatsappNumber): void {
    router.put(route('whatsapp_marketing.numbers.update', { number: number.id }), { is_active: !number.isActive }, { preserveScroll: true });
}

function makeDefault(number: WhatsappNumber): void {
    router.post(route('whatsapp_marketing.numbers.default', { number: number.id }), {}, { preserveScroll: true });
}

const removing = ref<WhatsappNumber | null>(null);

function remove(): void {
    if (removing.value === null) {
        return;
    }

    router.delete(route('whatsapp_marketing.numbers.destroy', { number: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('whatsapp.eyebrow')" :title="t('whatsapp.numbers.title')" :description="t('whatsapp.numbers.description')" />
    <WhatsappNav current="settings" />

    <div class="grid gap-3 lg:grid-cols-2">
        <Card :title="t('whatsapp.numbers.listTitle')" class="lg:col-span-2">
            <p v-if="addErrors.number" class="mb-3 text-sm text-danger" role="alert" data-testid="numbers-error">{{ addErrors.number }}</p>

            <EmptyState v-if="numbers.length === 0" :title="t('whatsapp.numbers.emptyTitle')" :description="t('whatsapp.numbers.emptyBody')">
                <Link v-if="communicationHref" :href="communicationHref" class="text-brand-text hover:underline">{{ t('whatsapp.settings.openCommunication') }}</Link>
            </EmptyState>

            <ul v-else class="grid gap-2">
                <li
                    v-for="number in numbers"
                    :key="number.id"
                    class="flex flex-wrap items-center gap-3 rounded-card border border-line bg-hover px-4 py-3"
                    data-testid="number-row"
                >
                    <Phone class="size-4 text-ink-muted" aria-hidden="true" />
                    <div class="min-w-0 grow">
                        <p class="font-medium text-ink wrap-anywhere">{{ number.label }}</p>
                        <p class="text-xs text-ink-subtle tabular">{{ number.displayNumber ?? number.accountId }}</p>
                    </div>
                    <Badge v-if="number.isDefault" tone="brand" dot>{{ t('whatsapp.numbers.default') }}</Badge>
                    <Badge :tone="number.isActive ? 'ok' : 'warn'">{{ number.isActive ? t('whatsapp.common.active') : t('whatsapp.common.inactive') }}</Badge>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="!number.isDefault && number.isActive" variant="ghost" size="sm" @click="makeDefault(number)">
                            <template #icon><Star class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.numbers.makeDefault') }}
                        </Button>
                        <Button v-if="!number.isDefault" variant="ghost" size="sm" @click="toggleActive(number)">
                            {{ number.isActive ? t('whatsapp.numbers.disable') : t('whatsapp.numbers.enable') }}
                        </Button>
                        <Button v-if="!number.isDefault" variant="ghost" size="sm" @click="removing = number">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.common.delete') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </Card>

        <Card :title="t('whatsapp.numbers.addTitle')" :description="t('whatsapp.numbers.addHint')">
            <p v-if="!connected" class="mb-3 text-sm text-ink-muted" data-testid="numbers-not-connected">{{ t('whatsapp.numbers.notConnected') }}</p>
            <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
                <Input v-model="add.account_id" :label="t('whatsapp.numbers.accountId')" :hint="t('whatsapp.numbers.accountIdHint')" :error="add.errors.account_id" inputmode="numeric" maxlength="32" />
                <Input v-model="add.label" :label="t('whatsapp.numbers.label')" :error="add.errors.label" maxlength="60" />
                <Input v-model="add.display_number" :label="t('whatsapp.numbers.displayNumber')" :error="add.errors.display_number" optional maxlength="32" />
                <Checkbox v-model="add.make_default" :label="t('whatsapp.numbers.makeDefaultLabel')" />
                <div><Button type="submit" variant="primary" :loading="add.processing">{{ t('whatsapp.numbers.add') }}</Button></div>
            </form>
        </Card>

        <Card :title="t('whatsapp.numbers.aboutTitle')">
            <p class="text-sm text-ink-muted">{{ t('whatsapp.numbers.aboutBody') }}</p>
        </Card>
    </div>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('whatsapp.numbers.removeTitle')"
        :description="t('whatsapp.numbers.removeBody', { label: removing?.label ?? '' })"
        :confirm-label="t('whatsapp.common.delete')"
        @confirm="remove"
        @update:open="(value: boolean) => { if (!value) removing = null; }"
    />
</template>

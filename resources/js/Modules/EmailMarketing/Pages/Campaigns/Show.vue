<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmailBadge from '../../Components/EmailBadge.vue';
import { isLocalDateTime } from '../../Components/emailFormat';
import { useEmailLabels } from '../../Components/useEmailLabels';
import type { CampaignDetail, DeliveryRow, Figures, MemberOption, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    campaign: CampaignDetail;
    deliveries: Paginated<DeliveryRow>;
    deliveryStatus: string | null;
    members: MemberOption[];
    maxTestRecipients: number;
    timezone: string;
}>();

const { t } = useI18n();
const format = useFormat();

type Dialog = 'schedule' | 'now' | 'cancel' | 'delete' | 'test' | null;
const dialog = ref<Dialog>(null);
const busy = ref(false);

const abilities = computed(() => props.campaign.abilities);
const report = computed(() => props.campaign.report);
const figures = computed<Figures>(() => report.value.figures);
const started = computed(() => props.campaign.counts.planned > 0 || props.campaign.status !== 'draft');
const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string | undefined>);
const labels = useEmailLabels();

const schedule = useForm({ scheduled_for: props.campaign.scheduledForLocal ?? '' });
const scheduleInvalid = computed(() => schedule.scheduled_for !== '' && !isLocalDateTime(schedule.scheduled_for));

const test = useForm<{ recipients: string[] }>({ recipients: [] });
const testTooMany = computed(() => test.recipients.length > props.maxTestRecipients);

function post(name: string, data: Record<string, unknown> = {}, then?: () => void): void {
    busy.value = true;
    router.post(route(name, { campaign: props.campaign.id }), data as Record<string, string>, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            dialog.value = null;
            then?.();
        },
    });
}

function submitSchedule(): void {
    schedule
        .transform((data) => ({ scheduled_for: data.scheduled_for === '' ? null : data.scheduled_for }))
        .post(route('email_marketing.campaigns.schedule', { campaign: props.campaign.id }), {
            preserveScroll: true,
            onSuccess: () => {
                dialog.value = null;
            },
        });
}

function submitTest(): void {
    test.post(route('email_marketing.campaigns.test', { campaign: props.campaign.id }), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = null;
            test.reset();
        },
    });
}

function destroy(): void {
    busy.value = true;
    router.delete(route('email_marketing.campaigns.destroy', { campaign: props.campaign.id }), {
        onFinish: () => {
            busy.value = false;
        },
    });
}

function filterDeliveries(status: string): void {
    router.get(
        route('email_marketing.campaigns.show', { campaign: props.campaign.id }),
        { delivery_status: status === 'all' ? undefined : status },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const statusFilter = ref(props.deliveryStatus ?? 'all');
const statusOptions = computed(() => [
    { value: 'all', label: t('emailMarketing.common.all') },
    ...(['pending', 'held', 'queued', 'sent', 'delivered', 'failed', 'bounced', 'blocked', 'skipped', 'cancelled'] as const).map((value) => ({
        value,
        label: t(`emailMarketing.status.delivery.${value}`),
    })),
]);

const columns = computed<Column[]>(() => [
    { key: 'contact', label: t('emailMarketing.fields.contact') },
    { key: 'status', label: t('emailMarketing.fields.status') },
    { key: 'variant', label: t('emailMarketing.fields.variant'), hideable: true, hiddenByDefault: !props.campaign.abEnabled },
    { key: 'sent', label: t('emailMarketing.fields.sentAt'), hideable: true },
    { key: 'clicks', label: t('emailMarketing.fields.clicks'), hideable: true, align: 'right' },
]);

function rate(value: number | null): string {
    return value === null ? t('emailMarketing.common.notReported') : format.number(value / 10000, { style: 'percent', maximumFractionDigits: 1 });
}

const tiles = computed(() => [
    { key: 'planned', value: figures.value.planned },
    { key: 'sent', value: figures.value.sentTotal },
    { key: 'blocked', value: figures.value.blocked },
    { key: 'skipped', value: figures.value.skipped },
    { key: 'bounced', value: figures.value.bounced },
    { key: 'failed', value: figures.value.failed },
]);
const variantKeys = computed(() => (['a', 'b'] as const).filter((variant) => report.value.variants[variant] !== undefined));
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.eyebrow')" :title="campaign.name" :description="campaign.subject">
        <template #actions>
            <EmailBadge kind="campaign" :value="campaign.status" />
            <Button v-if="abilities.edit" :href="route('email_marketing.campaigns.edit', { campaign: campaign.id })">{{ t('emailMarketing.common.edit') }}</Button>
            <Button v-if="abilities.duplicate" :disabled="busy" @click="post('email_marketing.campaigns.duplicate')">{{ t('emailMarketing.campaigns.duplicate') }}</Button>
            <Button v-if="abilities.test" @click="dialog = 'test'">{{ t('emailMarketing.campaigns.testSend') }}</Button>
            <Button v-if="abilities.schedule" variant="primary" @click="dialog = 'now'">{{ t('emailMarketing.campaigns.sendNow') }}</Button>
            <Button v-if="abilities.schedule" @click="dialog = 'schedule'">{{ t('emailMarketing.campaigns.schedule') }}</Button>
            <Button v-if="abilities.unschedule" :disabled="busy" @click="post('email_marketing.campaigns.unschedule')">{{ t('emailMarketing.campaigns.unschedule') }}</Button>
            <Button v-if="abilities.pause" :disabled="busy" @click="post('email_marketing.campaigns.pause')">{{ t('emailMarketing.campaigns.pause') }}</Button>
            <Button v-if="abilities.resume" variant="primary" :disabled="busy" @click="post('email_marketing.campaigns.resume')">{{ t('emailMarketing.campaigns.resume') }}</Button>
            <Button v-if="abilities.cancel" variant="ghost" @click="dialog = 'cancel'">{{ t('emailMarketing.campaigns.cancel') }}</Button>
            <Button v-if="abilities.delete" variant="ghost" @click="dialog = 'delete'">{{ t('emailMarketing.common.delete') }}</Button>
        </template>
    </PageHeader>

    <p v-if="errors.campaign" class="mb-4 text-sm text-danger" role="alert">{{ errors.campaign }}</p>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card :title="t('emailMarketing.campaigns.summary')">
                <dl class="grid gap-x-6 gap-y-3 text-[0.8125rem] sm:grid-cols-2">
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.fields.list') }}</dt>
                        <dd class="mt-1 text-ink">{{ campaign.listName }}<span v-if="campaign.segmentName" class="text-ink-muted"> / {{ campaign.segmentName }}</span></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.fields.owner') }}</dt>
                        <dd class="mt-1 text-ink">{{ campaign.owner?.name ?? t('emailMarketing.common.dash') }}</dd>
                    </div>
                    <div v-if="campaign.scheduledFor">
                        <dt class="eyebrow">{{ t('emailMarketing.fields.scheduledFor') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.date(campaign.scheduledFor, 'datetime') }}</dd>
                    </div>
                    <div v-if="campaign.finishedAt">
                        <dt class="eyebrow">{{ t('emailMarketing.fields.finishedAt') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.date(campaign.finishedAt, 'datetime') }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.campaigns.trackClicks') }}</dt>
                        <dd class="mt-1 text-ink">{{ campaign.trackClicks ? t('emailMarketing.common.yes') : t('emailMarketing.common.no') }}</dd>
                    </div>
                </dl>
                <p v-if="campaign.status === 'paused' && campaign.pausedReason" class="mt-4 text-sm text-warn" role="status">
                    {{ labels.pause(campaign.pausedReason) }}
                </p>
            </Card>

            <Card v-if="started" :title="t('emailMarketing.report.title')" :description="t('emailMarketing.report.note')">
                <dl class="grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-3" data-testid="campaign-figures">
                    <div v-for="tile in tiles" :key="tile.key">
                        <dt class="eyebrow">{{ t(`emailMarketing.report.${tile.key}`) }}</dt>
                        <dd class="figure mt-2 text-2xl text-ink">{{ format.number(tile.value) }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.report.clickers') }}</dt>
                        <dd class="figure mt-2 text-2xl text-ink">{{ format.number(figures.clickers) }}</dd>
                        <p class="text-xs text-ink-subtle">{{ rate(figures.clickRate) }}</p>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.report.viewers') }}</dt>
                        <dd class="figure mt-2 text-2xl text-ink">{{ format.number(figures.viewers) }}</dd>
                        <p class="text-xs text-ink-subtle">{{ rate(figures.viewRate) }}</p>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('emailMarketing.report.opens') }}</dt>
                        <dd class="mt-2 text-sm text-ink-muted">{{ t('emailMarketing.common.notMeasured') }}</dd>
                    </div>
                </dl>

                <div v-if="report.reasons.length > 0" class="mt-5">
                    <h3 class="eyebrow mb-2">{{ t('emailMarketing.report.reasons') }}</h3>
                    <ul class="grid gap-1 text-[0.8125rem] text-ink-muted">
                        <li v-for="row in report.reasons" :key="`${row.status}-${row.reason}`" class="flex gap-2">
                            <span class="tabular w-12 shrink-0 text-ink">{{ format.number(row.count) }}</span>
                            <span>{{ labels.reason(row.reason) }} <span class="text-ink-subtle">({{ t(`emailMarketing.status.delivery.${row.status}`) }})</span></span>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card v-if="campaign.ab.enabled" :title="t('emailMarketing.ab.title')">
                <p v-if="campaign.ab.winner" class="mb-3 text-sm text-ink" role="status">
                    {{ t('emailMarketing.ab.winner', { variant: campaign.ab.winner.toUpperCase() }) }}
                    <span class="text-ink-muted">{{ t(`emailMarketing.ab.reason.${campaign.ab.winnerReason ?? 'clicks'}`) }}</span>
                </p>
                <p v-else-if="campaign.ab.decideAt" class="mb-3 text-sm text-ink-muted" role="status">
                    {{ t('emailMarketing.ab.waiting', { when: format.date(campaign.ab.decideAt, 'datetime') }) }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-[0.8125rem]">
                        <caption class="sr-only">{{ t('emailMarketing.ab.title') }}</caption>
                        <thead>
                            <tr class="text-left text-ink-subtle">
                                <th scope="col" class="py-1 pr-4 font-medium">{{ t('emailMarketing.fields.variant') }}</th>
                                <th scope="col" class="py-1 pr-4 font-medium">{{ t('emailMarketing.fields.subject') }}</th>
                                <th scope="col" class="py-1 pr-4 text-right font-medium">{{ t('emailMarketing.report.sent') }}</th>
                                <th scope="col" class="py-1 pr-4 text-right font-medium">{{ t('emailMarketing.report.clickers') }}</th>
                                <th scope="col" class="py-1 text-right font-medium">{{ t('emailMarketing.report.clickRate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variant in variantKeys" :key="variant" class="border-t border-line">
                                <th scope="row" class="py-2 pr-4 text-left font-medium text-ink">{{ variant.toUpperCase() }}</th>
                                <td class="py-2 pr-4 text-ink-muted">{{ variant === 'a' ? campaign.subject : campaign.ab.subjectB }}</td>
                                <td class="tabular py-2 pr-4 text-right">{{ format.number(report.variants[variant]?.sentTotal ?? 0) }}</td>
                                <td class="tabular py-2 pr-4 text-right">{{ format.number(report.variants[variant]?.clickers ?? 0) }}</td>
                                <td class="tabular py-2 text-right">{{ rate(report.variants[variant]?.clickRate ?? null) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-ink-subtle">{{ t('emailMarketing.ab.note', { percent: campaign.ab.percent, hours: campaign.ab.waitHours }) }}</p>
            </Card>

            <Card v-if="started" :title="t('emailMarketing.deliveries.title')" flush>
                <div class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3">
                    <Select
                        v-model="statusFilter"
                        :options="statusOptions"
                        :label="t('emailMarketing.fields.status')"
                        class="w-full sm:w-48"
                        @update:model-value="filterDeliveries(String($event))"
                    />
                    <Button v-if="abilities.export" size="sm" :href="route('email_marketing.campaigns.export', { campaign: campaign.id })">
                        {{ t('emailMarketing.deliveries.export') }}
                    </Button>
                </div>
                <DataTable :columns="columns" :rows="deliveries.data" row-key="id" :caption="t('emailMarketing.deliveries.caption')">
                    <template #cell-contact="{ row }">
                        <span class="text-ink">{{ row.contact.name }}</span>
                        <span class="block text-xs text-ink-subtle">{{ row.contact.email }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <EmailBadge kind="delivery" :value="row.status" />
                        <span v-if="row.reason" class="mt-1 block text-xs text-ink-subtle">{{ labels.reason(row.reason) }}</span>
                    </template>
                    <template #cell-variant="{ row }"><span class="text-ink-muted">{{ row.variant.toUpperCase() }}</span></template>
                    <template #cell-sent="{ row }">
                        <span class="tabular text-ink-muted">{{ row.sentAt ? format.date(row.sentAt, 'datetime') : t('emailMarketing.common.dash') }}</span>
                    </template>
                    <template #cell-clicks="{ row }"><span class="tabular text-ink-muted">{{ format.number(row.clickCount) }}</span></template>
                    <template #empty>
                        <EmptyState :title="t('emailMarketing.deliveries.empty')" compact />
                    </template>
                </DataTable>
                <Pagination :meta="deliveries" />
            </Card>
        </div>

        <Card :title="t('emailMarketing.preview.title')" class="self-start lg:sticky lg:top-4">
            <p class="mb-2 text-[0.8125rem] text-ink-muted">
                <span class="eyebrow mr-2">{{ t('emailMarketing.preview.subject') }}</span>{{ campaign.preview.subject }}
            </p>
            <iframe
                class="h-[26rem] w-full rounded-card border border-line bg-white"
                sandbox=""
                referrerpolicy="no-referrer"
                :title="t('emailMarketing.preview.frameTitle')"
                :srcdoc="campaign.preview.html"
            />
            <p class="mt-2 text-[0.75rem] text-ink-subtle">{{ t('emailMarketing.preview.plainNote') }}</p>
        </Card>
    </div>

    <Modal :open="dialog === 'schedule'" :title="t('emailMarketing.campaigns.scheduleTitle')" :description="t('emailMarketing.campaigns.scheduleBody', { timezone })" size="sm" @update:open="dialog = $event ? 'schedule' : null">
        <Input
            v-model="schedule.scheduled_for"
            type="datetime-local"
            :label="t('emailMarketing.fields.scheduledFor')"
            :error="schedule.errors.scheduled_for ?? (scheduleInvalid ? t('emailMarketing.campaigns.dateInvalid') : undefined)"
        />
        <template #footer>
            <Button variant="ghost" @click="dialog = null">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button variant="primary" :loading="schedule.processing" :disabled="schedule.scheduled_for === '' || scheduleInvalid" @click="submitSchedule">
                {{ t('emailMarketing.campaigns.schedule') }}
            </Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="dialog === 'now'"
        tone="default"
        :title="t('emailMarketing.campaigns.sendNowTitle')"
        :description="t('emailMarketing.campaigns.sendNowBody')"
        :confirm-label="t('emailMarketing.campaigns.sendNow')"
        :loading="busy"
        @update:open="dialog = $event ? 'now' : null"
        @confirm="post('email_marketing.campaigns.schedule')"
    />
    <ConfirmDialog
        :open="dialog === 'cancel'"
        :title="t('emailMarketing.campaigns.cancelTitle')"
        :description="t('emailMarketing.campaigns.cancelBody')"
        :confirm-label="t('emailMarketing.campaigns.cancel')"
        :loading="busy"
        @update:open="dialog = $event ? 'cancel' : null"
        @confirm="post('email_marketing.campaigns.cancel')"
    />
    <ConfirmDialog
        :open="dialog === 'delete'"
        :title="t('emailMarketing.campaigns.deleteTitle')"
        :description="t('emailMarketing.campaigns.deleteBody')"
        :confirm-label="t('emailMarketing.common.delete')"
        :loading="busy"
        @update:open="dialog = $event ? 'delete' : null"
        @confirm="destroy"
    />

    <Modal :open="dialog === 'test'" :title="t('emailMarketing.campaigns.testTitle')" :description="t('emailMarketing.campaigns.testBody', { count: maxTestRecipients })" @update:open="dialog = $event ? 'test' : null">
        <fieldset class="grid gap-2">
            <legend class="sr-only">{{ t('emailMarketing.campaigns.testTitle') }}</legend>
            <label v-for="member in members" :key="member.id" class="flex items-center gap-2 text-sm text-ink">
                <input v-model="test.recipients" type="checkbox" :value="member.id" class="size-4" />
                <span>{{ member.name }} <span class="text-ink-subtle">{{ member.email }}</span></span>
            </label>
        </fieldset>
        <p v-if="testTooMany" class="mt-2 text-sm text-danger" role="alert">{{ t('emailMarketing.campaigns.testTooMany', { count: maxTestRecipients }) }}</p>
        <p v-if="test.errors.recipients" class="mt-2 text-sm text-danger" role="alert">{{ test.errors.recipients }}</p>
        <p class="mt-3 text-[0.8125rem] text-ink-subtle">{{ t('emailMarketing.campaigns.testNote') }}</p>
        <template #footer>
            <Button variant="ghost" @click="dialog = null">{{ t('emailMarketing.common.cancel') }}</Button>
            <Button variant="primary" :loading="test.processing" :disabled="test.recipients.length === 0 || testTooMany" @click="submitTest">
                {{ t('emailMarketing.campaigns.testSend') }}
            </Button>
        </template>
    </Modal>
</template>

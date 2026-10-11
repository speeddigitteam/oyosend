<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Ban, CreditCard, Ellipsis, ExternalLink, FilePlus2, Mail, Pencil, ReceiptText, Send, Trash2, Undo2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownSeparator from '@/Core/Components/DropdownSeparator.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CreditNoteDialog from '../../Components/CreditNoteDialog.vue';
import InvoiceForm from '../../Components/InvoiceForm.vue';
import InvoiceStatusBadge from '../../Components/InvoiceStatusBadge.vue';
import InvoiceTimeline from '../../Components/InvoiceTimeline.vue';
import InvoiceTotals from '../../Components/InvoiceTotals.vue';
import InvoicingNav from '../../Components/InvoicingNav.vue';
import PaymentDialog from '../../Components/PaymentDialog.vue';
import ReasonDialog from '../../Components/ReasonDialog.vue';
import SendDialog from '../../Components/SendDialog.vue';
import type { InvoiceAbilities, InvoiceDetail, InvoiceFormOptions, InvoicePayment, PaymentMethod } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    invoice: InvoiceDetail;
    abilities: InvoiceAbilities;
    paymentMethods: PaymentMethod[];
    form: InvoiceFormOptions;
}>();

const { t } = useI18n();
const format = useFormat();

const editing = ref(false);
const issuing = ref(false);
const deleting = ref(false);
const sending = ref(false);
const paying = ref(false);
const voiding = ref(false);
const crediting = ref(false);
const reversing = ref<InvoicePayment | null>(null);
const busy = ref(false);

const isDraft = computed(() => props.invoice.status === 'draft');
const hasMore = computed(() => props.abilities.issueCreditNote || props.abilities.void || props.abilities.delete);
const documentUrl = computed(() => route('invoicing.invoices.document', { invoice: props.invoice.id }));
const downloadUrl = computed(() => route('invoicing.invoices.document', { invoice: props.invoice.id, download: '1' }));
const title = computed(() => props.invoice.number ?? t('invoicing.invoices.draftTitle'));
const reverseUrl = computed(() => reversing.value
    ? route('invoicing.invoices.payments.reverse', { invoice: props.invoice.id, payment: reversing.value.id })
    : '');

const lineColumns = computed<Column[]>(() => [
    { key: 'name', label: t('invoicing.fields.item') },
    { key: 'quantity', label: t('invoicing.fields.quantity'), align: 'right' },
    { key: 'unitPrice', label: t('invoicing.fields.unitPrice'), align: 'right' },
    { key: 'discount', label: t('invoicing.fields.discount'), align: 'right', hideable: true },
    { key: 'tax', label: t('invoicing.fields.tax'), align: 'right', hideable: true },
    { key: 'total', label: t('invoicing.fields.lineTotal'), align: 'right' },
]);
const paymentColumns = computed<Column[]>(() => [
    { key: 'paidOn', label: t('invoicing.fields.paidOn') },
    { key: 'method', label: t('invoicing.fields.method') },
    { key: 'reference', label: t('invoicing.fields.paymentReference'), hideable: true },
    { key: 'recordedBy', label: t('invoicing.fields.recordedBy'), hideable: true },
    { key: 'amount', label: t('invoicing.fields.amount'), align: 'right' },
    { key: 'actions', label: t('invoicing.common.actions'), srOnly: true, align: 'right' },
]);

function issue(): void {
    busy.value = true;
    router.post(route('invoicing.invoices.issue', { invoice: props.invoice.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; issuing.value = false; },
    });
}

function remove(): void {
    busy.value = true;
    router.delete(route('invoicing.invoices.destroy', { invoice: props.invoice.id }), {
        onFinish: () => { busy.value = false; deleting.value = false; },
    });
}

function openDocument(url: string): void {
    window.open(url, '_blank', 'noopener');
}

function percent(bps: number): string {
    return `${format.number(bps / 100, { maximumFractionDigits: 2 })}%`;
}

function quantity(milli: number): string {
    return format.number(milli / 1000, { maximumFractionDigits: 3 });
}
</script>

<template>
    <PageHeader :eyebrow="t('invoicing.eyebrow')" :title="title" :description="invoice.customer.name ?? undefined">
        <template #actions>
            <InvoiceStatusBadge :value="invoice.status" />
            <Button v-if="abilities.edit && !editing" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.common.edit') }}
            </Button>
            <Button v-if="abilities.issue" variant="primary" @click="issuing = true">
                <template #icon><FilePlus2 class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.invoices.issue') }}
            </Button>
            <Button v-if="abilities.send" @click="sending = true">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.send.action') }}
            </Button>
            <Button v-if="abilities.recordPayment" variant="primary" @click="paying = true">
                <template #icon><CreditCard class="size-4" aria-hidden="true" /></template>
                {{ t('invoicing.payments.record') }}
            </Button>
            <Dropdown v-if="hasMore">
                <template #trigger>
                    <Button icon variant="secondary" :aria-label="t('invoicing.common.moreActions')"><Ellipsis class="size-4" aria-hidden="true" /></Button>
                </template>
                <DropdownItem v-if="abilities.issueCreditNote" @select="crediting = true">
                    <ReceiptText aria-hidden="true" />{{ t('invoicing.creditNotes.issue') }}
                </DropdownItem>
                <DropdownSeparator v-if="abilities.issueCreditNote && (abilities.void || abilities.delete)" />
                <DropdownItem v-if="abilities.void" tone="danger" @select="voiding = true">
                    <Ban aria-hidden="true" />{{ t('invoicing.invoices.void') }}
                </DropdownItem>
                <DropdownItem v-if="abilities.delete" tone="danger" @select="deleting = true">
                    <Trash2 aria-hidden="true" />{{ t('invoicing.invoices.delete') }}
                </DropdownItem>
            </Dropdown>
        </template>
    </PageHeader>
    <InvoicingNav current="invoices" />

    <InvoiceForm v-if="editing && abilities.edit" :options="form" :invoice="invoice" @saved="editing = false" @cancel="editing = false" />

    <div v-else class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Card><p class="eyebrow">{{ t('invoicing.fields.total') }}</p><p class="figure mt-2 text-xl text-ink">{{ format.money(invoice.totalAmount, invoice.currency) }}</p></Card>
                <Card><p class="eyebrow">{{ t('invoicing.totals.paid') }}</p><p class="figure mt-2 text-xl text-ink">{{ format.money(invoice.paidAmount, invoice.currency) }}</p></Card>
                <Card><p class="eyebrow">{{ t('invoicing.totals.credited') }}</p><p class="figure mt-2 text-xl text-ink">{{ format.money(invoice.creditedAmount, invoice.currency) }}</p></Card>
                <Card><p class="eyebrow">{{ t('invoicing.totals.balance') }}</p><p class="figure mt-2 text-xl text-ink" data-testid="balance">{{ format.money(invoice.balanceAmount, invoice.currency) }}</p></Card>
            </div>

            <Card :title="t('invoicing.form.lines')" flush>
                <DataTable :columns="lineColumns" :rows="invoice.lines" row-key="id" :caption="t('invoicing.show.linesCaption')">
                    <template #cell-name="{ row }">
                        <span class="block text-ink">{{ row.name }}</span>
                        <span v-if="row.description" class="block max-w-sm text-xs text-ink-subtle">{{ row.description }}</span>
                    </template>
                    <template #cell-quantity="{ row }"><span class="tabular font-mono">{{ quantity(row.quantityMilli) }}<template v-if="row.unit && row.unit !== 'unit'"> {{ row.unit }}</template></span></template>
                    <template #cell-unitPrice="{ row }"><span class="tabular font-mono">{{ format.money(row.unitPriceAmount, invoice.currency) }}</span></template>
                    <template #cell-discount="{ row }">
                        <span class="tabular font-mono text-ink-muted">{{ row.discountAmount > 0 ? format.money(row.discountAmount, invoice.currency) : t('invoicing.common.dash') }}</span>
                        <span v-if="row.discountBps" class="block text-xs text-ink-subtle">{{ percent(row.discountBps) }}</span>
                    </template>
                    <template #cell-tax="{ row }">
                        <span class="tabular font-mono text-ink-muted">{{ row.taxBps > 0 ? format.money(row.taxAmount, invoice.currency) : t('invoicing.common.dash') }}</span>
                        <span v-if="row.taxBps > 0" class="block text-xs text-ink-subtle">{{ row.taxName ? `${row.taxName} ` : '' }}{{ percent(row.taxBps) }}</span>
                    </template>
                    <template #cell-total="{ row }"><span class="tabular font-mono text-ink">{{ format.money(row.lineTotalAmount, invoice.currency) }}</span></template>
                </DataTable>
                <div class="border-t border-line px-5 py-4">
                    <InvoiceTotals :currency="invoice.currency" :subtotal="invoice.subtotalAmount" :discount="invoice.discountAmount" :tax="invoice.taxAmount" :total="invoice.totalAmount" :paid="invoice.paidAmount" :credited="invoice.creditedAmount" :balance="isDraft ? undefined : invoice.balanceAmount" />
                </div>
            </Card>

            <Card v-if="!isDraft || invoice.payments.length > 0" :title="t('invoicing.payments.title')" flush>
                <DataTable :columns="paymentColumns" :rows="invoice.payments" row-key="id" :caption="t('invoicing.payments.caption')">
                    <template #cell-paidOn="{ row }"><span class="tabular">{{ format.date(row.paidOn) }}</span></template>
                    <template #cell-method="{ row }">
                        {{ t(`invoicing.methods.${row.method}`) }}
                        <Badge v-if="row.reversedAt" tone="danger" class="ml-1">{{ t('invoicing.payments.reversed') }}</Badge>
                    </template>
                    <template #cell-reference="{ row }"><span class="font-mono text-xs text-ink-muted">{{ row.reference ?? t('invoicing.common.dash') }}</span></template>
                    <template #cell-recordedBy="{ row }"><span class="text-ink-muted">{{ row.recordedBy ?? t('invoicing.common.dash') }}</span></template>
                    <template #cell-amount="{ row }"><span :class="['tabular font-mono', row.reversedAt ? 'text-ink-subtle line-through' : '']">{{ format.money(row.amount, row.currency) }}</span></template>
                    <template #cell-actions="{ row }">
                        <Button v-if="abilities.reversePayment && !row.reversedAt" size="sm" variant="ghost" @click="reversing = row">
                            <template #icon><Undo2 class="size-4" aria-hidden="true" /></template>
                            {{ t('invoicing.payments.reverse') }}
                        </Button>
                    </template>
                    <template #empty>
                        <EmptyState compact :title="t('invoicing.payments.empty')" :description="t('invoicing.payments.emptyBody')" />
                    </template>
                </DataTable>
            </Card>

            <Card v-if="invoice.creditNotes.length > 0" :title="t('invoicing.creditNotes.title')">
                <ul class="grid gap-3">
                    <li v-for="note in invoice.creditNotes" :key="note.id" class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-ink">{{ note.number }}</p>
                            <p class="text-[0.8125rem] text-ink-muted">{{ format.date(note.issuedOn) }}<template v-if="note.reason"> · {{ note.reason }}</template></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="tabular font-mono text-ink">{{ format.money(note.totalAmount, note.currency) }}</span>
                            <Button size="sm" variant="ghost" @click="openDocument(route('invoicing.invoices.credit-notes.document', { invoice: invoice.id, creditNote: note.id }))">
                                <template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>
                                {{ t('invoicing.document.open') }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card v-if="invoice.terms || invoice.notes" :title="t('invoicing.form.termsAndNotes')">
                <div class="grid gap-4 text-sm">
                    <div v-if="invoice.terms"><p class="eyebrow mb-1">{{ t('invoicing.fields.terms') }}</p><p class="whitespace-pre-line text-ink-muted">{{ invoice.terms }}</p></div>
                    <div v-if="invoice.notes"><p class="eyebrow mb-1">{{ t('invoicing.fields.notes') }}</p><p class="whitespace-pre-line text-ink-muted">{{ invoice.notes }}</p></div>
                </div>
            </Card>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('invoicing.show.details')">
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('invoicing.fields.customer') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ invoice.snapshot?.name ?? invoice.customer.name ?? t('invoicing.common.notSet') }}</dd>
                        <dd v-if="invoice.snapshot?.contactName" class="text-ink-muted">{{ invoice.snapshot.contactName }}</dd>
                        <dd v-if="invoice.customerEmail" class="text-ink-muted">{{ invoice.customerEmail }}</dd>
                        <dd v-if="invoice.snapshot?.phone" class="text-ink-muted">{{ invoice.snapshot.phone }}</dd>
                        <dd v-if="invoice.snapshot?.address" class="whitespace-pre-line text-ink-muted">{{ invoice.snapshot.address }}</dd>
                        <dd v-if="invoice.snapshot?.taxId" class="text-ink-muted">{{ t('invoicing.fields.taxId') }}: {{ invoice.snapshot.taxId }}</dd>
                    </div>
                    <div v-if="invoice.seller?.name">
                        <dt class="eyebrow">{{ t('invoicing.show.seller') }}</dt>
                        <dd class="mt-0.5 text-ink">{{ invoice.seller.name }}</dd>
                    </div>
                    <div v-if="invoice.title"><dt class="eyebrow">{{ t('invoicing.fields.title') }}</dt><dd class="mt-0.5 text-ink">{{ invoice.title }}</dd></div>
                    <div v-if="invoice.reference"><dt class="eyebrow">{{ t('invoicing.fields.reference') }}</dt><dd class="mt-0.5 font-mono text-ink">{{ invoice.reference }}</dd></div>
                    <div><dt class="eyebrow">{{ t('invoicing.fields.issuedOn') }}</dt><dd class="tabular mt-0.5 text-ink">{{ invoice.issuedOn ? format.date(invoice.issuedOn) : t('invoicing.show.notIssued') }}</dd></div>
                    <div><dt class="eyebrow">{{ t('invoicing.fields.dueOn') }}</dt><dd class="tabular mt-0.5 text-ink">{{ invoice.dueOn ? format.date(invoice.dueOn) : t('invoicing.common.dash') }}</dd></div>
                    <div v-if="invoice.owner"><dt class="eyebrow">{{ t('invoicing.fields.owner') }}</dt><dd class="mt-0.5 text-ink">{{ invoice.owner.name }}</dd></div>
                    <div v-if="invoice.source"><dt class="eyebrow">{{ t('invoicing.fields.source') }}</dt><dd class="mt-0.5 text-ink">{{ t(`invoicing.sources.${invoice.source.type}`) }}</dd></div>
                </dl>
            </Card>

            <Card v-if="!isDraft" :title="t('invoicing.document.title')" :description="t('invoicing.document.description')">
                <div class="flex flex-wrap items-center gap-3">
                    <Button size="sm" @click="openDocument(documentUrl)">
                        <template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>
                        {{ t('invoicing.document.open') }}
                    </Button>
                    <a :href="downloadUrl" class="text-sm font-medium text-brand-text underline underline-offset-2" data-testid="download-link">{{ t('invoicing.document.download') }}</a>
                </div>
                <p v-if="invoice.sentAt" class="mt-3 flex items-center gap-1.5 text-[0.8125rem] text-ink-subtle">
                    <Mail class="size-4" aria-hidden="true" />{{ t('invoicing.document.sentOn', { date: format.date(invoice.sentAt, 'datetime') }) }}
                </p>
            </Card>

            <InvoiceTimeline :invoice="invoice" />
        </div>
    </div>

    <ConfirmDialog v-model:open="issuing" tone="default" :title="t('invoicing.invoices.issueConfirmTitle')" :description="t('invoicing.invoices.issueConfirmBody')" :confirm-label="t('invoicing.invoices.issue')" :loading="busy" @confirm="issue" />
    <ConfirmDialog v-model:open="deleting" :title="t('invoicing.invoices.deleteConfirmTitle')" :description="t('invoicing.invoices.deleteConfirmBody')" :confirm-label="t('invoicing.invoices.delete')" :loading="busy" @confirm="remove" />
    <SendDialog v-if="abilities.send" v-model:open="sending" :invoice-id="invoice.id" :default-email="invoice.customerEmail" />
    <PaymentDialog v-if="abilities.recordPayment" v-model:open="paying" :invoice-id="invoice.id" :currency="invoice.currency" :balance="invoice.balanceAmount" :methods="paymentMethods" />
    <CreditNoteDialog v-if="abilities.issueCreditNote" v-model:open="crediting" :invoice="invoice" />
    <ReasonDialog v-if="abilities.void" v-model:open="voiding" danger :title="t('invoicing.invoices.voidTitle')" :description="t('invoicing.invoices.voidBody')" :confirm-label="t('invoicing.invoices.void')" :url="route('invoicing.invoices.void', { invoice: invoice.id })" />
    <ReasonDialog v-if="abilities.reversePayment" :open="reversing !== null" danger :title="t('invoicing.payments.reverseTitle')" :description="t('invoicing.payments.reverseBody')" :confirm-label="t('invoicing.payments.reverse')" :url="reverseUrl" @update:open="!$event && (reversing = null)" />
</template>

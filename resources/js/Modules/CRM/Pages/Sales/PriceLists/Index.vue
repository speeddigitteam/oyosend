<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ListPlus, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import SalesNav from '../../../Components/SalesNav.vue';
import type { PriceList, PriceListItem } from '../../../Components/types';

interface PriceProduct { id: string; name: string; sku: string | null; unit: string; currency: string }

defineOptions({ layout: TenantLayout });
const props = defineProps<{ priceLists: Paginated<PriceList>; filters: { search: string }; products: PriceProduct[] }>();
const { t } = useI18n();
const { can } = useAccess();
const format = useFormat();
const page = usePage();
const manage = computed(() => can('crm.products.manage'));
const search = ref(props.filters.search);
const listOpen = ref(false);
const priceOpen = ref(false);
const listTarget = ref<PriceList | null>(null);
const selected = ref<PriceList | null>(null);
const deleting = ref<{ list: PriceList; item: PriceListItem } | null>(null);
const deleteBusy = ref(false);
const list = useForm({ name: '', currency: page.props.app.currency, starts_on: '', ends_on: '', is_active: true });
const price = useForm({ product_id: '', minimum_quantity_milli: 1000, unit_price_amount: 0 });

function apply(): void {
    router.get(route('crm.sales.price-lists.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}
function openList(target: PriceList | null): void {
    listTarget.value = target;
    list.name = target?.name ?? '';
    list.currency = target?.currency ?? page.props.app.currency;
    list.starts_on = target?.startsOn ?? '';
    list.ends_on = target?.endsOn ?? '';
    list.is_active = target?.active ?? true;
    list.clearErrors();
    listOpen.value = true;
}
function saveList(): void {
    const settings = { preserveScroll: true, onSuccess: () => { listOpen.value = false; list.reset(); listTarget.value = null; } };
    if (listTarget.value) list.put(route('crm.sales.price-lists.update', { priceList: listTarget.value.id }), settings);
    else list.post(route('crm.sales.price-lists.store'), settings);
}
function addPrice(target: PriceList): void {
    selected.value = target;
    price.product_id = '';
    price.minimum_quantity_milli = 1000;
    price.unit_price_amount = 0;
    price.clearErrors();
    priceOpen.value = true;
}
function savePrice(): void {
    if (!selected.value) return;
    price.post(route('crm.sales.price-lists.items.store', { priceList: selected.value.id }), {
        preserveScroll: true,
        onSuccess: () => { priceOpen.value = false; price.reset(); },
    });
}
function removePrice(): void {
    if (!deleting.value) return;
    deleteBusy.value = true;
    router.delete(route('crm.sales.price-lists.items.destroy', {
        priceList: deleting.value.list.id,
        priceListItem: deleting.value.item.id,
    }), {
        preserveScroll: true,
        onFinish: () => { deleteBusy.value = false; deleting.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('crm.eyebrow')" :title="t('crm.sales.priceLists.title')" :description="t('crm.sales.priceLists.description')">
        <template v-if="manage" #actions><Button variant="primary" @click="openList(null)"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('crm.sales.priceLists.add') }}</Button></template>
    </PageHeader>
    <SalesNav current="priceLists" />
    <form class="mb-3 flex max-w-xl items-end gap-2" @submit.prevent="apply"><Input v-model="search" :label="t('crm.sales.priceLists.search')" class="flex-1" type="search"><template #leading><Search class="size-4" aria-hidden="true" /></template></Input><Button type="submit" size="sm">{{ t('crm.common.search') }}</Button></form>
    <Card v-if="priceLists.total === 0"><EmptyState :title="t('crm.sales.priceLists.empty')"><template #icon><ListPlus /></template></EmptyState></Card>
    <div v-else class="grid gap-3 lg:grid-cols-2">
        <Card v-for="listItem in priceLists.data" :key="listItem.id" :title="listItem.name" flush>
            <template #actions>
                <Badge :tone="listItem.active ? 'ok' : 'neutral'" dot>{{ listItem.active ? t('crm.status.active') : t('crm.sales.catalogue.inactive') }}</Badge>
                <Button v-if="manage" icon size="sm" variant="ghost" :aria-label="t('crm.sales.priceLists.edit')" @click="openList(listItem)"><template #icon><Pencil class="size-4" aria-hidden="true" /></template></Button>
                <Button v-if="manage" size="sm" variant="ghost" @click="addPrice(listItem)">{{ t('crm.sales.priceLists.addPrice') }}</Button>
            </template>
            <div class="px-5 pb-3 text-xs text-ink-subtle"><span>{{ listItem.currency }}</span><span v-if="listItem.startsOn"> · {{ t('crm.sales.priceLists.startsOn') }} {{ format.date(listItem.startsOn) }}</span><span v-if="listItem.endsOn"> · {{ t('crm.sales.priceLists.endsOn') }} {{ format.date(listItem.endsOn) }}</span></div>
            <EmptyState v-if="listItem.items.length === 0" :title="t('crm.sales.priceLists.items')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in listItem.items" :key="item.id" class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0"><p class="truncate text-sm text-ink">{{ item.product.name }}</p><p class="text-xs text-ink-subtle">{{ t('crm.sales.priceLists.minimumQuantity') }} {{ format.number(item.minimumQuantityMilli / 1000) }} {{ item.product.unit }}</p></div>
                    <div class="flex shrink-0 items-center gap-1"><span class="tabular font-mono text-sm text-ink">{{ format.money(item.unitPriceAmount, listItem.currency) }}</span><Button v-if="manage" icon size="sm" variant="ghost" :aria-label="t('crm.sales.priceLists.removePrice', { name: item.product.name })" @click="deleting = { list: listItem, item }"><template #icon><Trash2 class="size-4 text-danger" aria-hidden="true" /></template></Button></div>
                </li>
            </ul>
        </Card>
    </div>
    <Pagination :meta="priceLists" />

    <Modal v-model:open="listOpen" :title="t(listTarget ? 'crm.sales.priceLists.edit' : 'crm.sales.priceLists.add')" :persistent="list.processing">
        <form id="crm-price-list-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveList"><Input v-model="list.name" class="sm:col-span-2" :label="t('crm.fields.name')" :error="list.errors.name" required /><Input v-model="list.currency" :label="t('crm.fields.currency')" :error="list.errors.currency" required maxlength="3" /><Input v-model="list.starts_on" type="date" :label="t('crm.sales.priceLists.startsOn')" :error="list.errors.starts_on" optional /><Input v-model="list.ends_on" type="date" :label="t('crm.sales.priceLists.endsOn')" :error="list.errors.ends_on" optional /><Checkbox v-model="list.is_active" :label="t('crm.status.active')" /></form>
        <template #footer><Button variant="ghost" @click="listOpen = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-price-list-form" type="submit" variant="primary" :loading="list.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>
    <Modal v-model:open="priceOpen" :title="t('crm.sales.priceLists.addPrice')" :persistent="price.processing">
        <form id="crm-price-form" class="grid gap-4" @submit.prevent="savePrice"><Select v-model="price.product_id" :options="products.map((item) => ({ value: item.id, label: item.name }))" :label="t('crm.sales.catalogue.title')" :error="price.errors.product_id" required /><Input v-model="price.minimum_quantity_milli" type="number" min="1" :label="t('crm.sales.priceLists.minimumQuantity')" :error="price.errors.minimum_quantity_milli" required /><Input v-model="price.unit_price_amount" type="number" min="0" :label="t('crm.fields.unitPrice')" :error="price.errors.unit_price_amount" required /></form>
        <template #footer><Button variant="ghost" @click="priceOpen = false">{{ t('crm.common.cancel') }}</Button><Button form="crm-price-form" type="submit" variant="primary" :loading="price.processing">{{ t('crm.common.save') }}</Button></template>
    </Modal>
    <ConfirmDialog :open="deleting !== null" :title="t('crm.sales.priceLists.removePriceTitle')" :description="deleting?.item.product.name" :confirm-label="t('crm.sales.priceLists.removePriceConfirm')" :loading="deleteBusy" @update:open="!$event && (deleting = null)" @confirm="removePrice" />
</template>

<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CrmStatusBadge from '../../../Components/CrmStatusBadge.vue';
import RecordContentPanels from '../../../Components/RecordContentPanels.vue';
import SalesNav from '../../../Components/SalesNav.vue';
import TimelineList from '../../../Components/TimelineList.vue';
import type { CustomerProfile, RecordContent, TimelineEntry } from '../../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    customer: CustomerProfile;
    entityType: 'contact' | 'company';
    entityId: string;
    content: RecordContent;
    timeline: TimelineEntry[];
}>();

const { t } = useI18n();
const format = useFormat();
const name = props.customer.company?.name ?? props.customer.contact?.name ?? t('crm.common.notSet');
const email = props.customer.company?.email ?? props.customer.contact?.email ?? undefined;
</script>

<template>
    <PageHeader :eyebrow="t('crm.sales.customers.title')" :title="name" :description="email">
        <template #actions>
            <Button variant="ghost" :href="route('crm.sales.customers.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('crm.sales.customers.title') }}
            </Button>
        </template>
    </PageHeader>
    <SalesNav current="customers" />

    <div class="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <div class="grid gap-3">
            <Card :title="t('crm.sales.customers.edit')">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="eyebrow">{{ t('crm.fields.stage') }}</dt>
                        <dd class="mt-1"><CrmStatusBadge :status="customer.stage" /></dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('crm.fields.owner') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ customer.owner?.name ?? t('crm.common.unassigned') }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('crm.sales.customers.nextFollowUp') }}</dt>
                        <dd class="tabular mt-1 text-sm text-ink">{{ customer.nextFollowUpAt ? format.date(customer.nextFollowUpAt, 'datetime') : t('crm.common.notSet') }}</dd>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="eyebrow">{{ t('crm.sales.customers.lifetimeValue') }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-3">
                            <span v-for="(amount, currency) in customer.lifetimeValues" :key="currency" class="tabular font-mono text-sm text-ink">{{ format.money(amount, currency) }}</span>
                            <span v-if="Object.keys(customer.lifetimeValues).length === 0" class="text-sm text-ink-muted">{{ t('crm.common.notSet') }}</span>
                        </dd>
                    </div>
                </dl>
            </Card>
            <RecordContentPanels
                :kind="entityType"
                :record-id="entityId"
                :route-id="customer.id"
                :content="content"
                :editable="customer.editable"
            />
        </div>
        <div class="grid content-start gap-3">
            <TimelineList :entries="timeline" />
        </div>
    </div>
</template>

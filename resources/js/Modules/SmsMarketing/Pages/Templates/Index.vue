<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import DataTable, { type Column } from '@/Core/Components/DataTable.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import SegmentMeter from '../../Components/SegmentMeter.vue';
import SmsBadge from '../../Components/SmsBadge.vue';
import SmsFormDialog from '../../Components/SmsFormDialog.vue';
import { countSegments, unknownVariables } from '../../Components/smsFormat';
import type { TemplatePurpose, TemplateRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    templates: TemplateRow[];
    variables: { marketing: string[]; transactional: string[] };
    purposes: TemplatePurpose[];
    maxBody: number;
    canManage: boolean;
}>();

const { t } = useI18n();

const open = ref(false);
const editing = ref<TemplateRow | null>(null);
const removing = ref<TemplateRow | null>(null);
const removeOpen = ref(false);
const busy = ref(false);

const form = useForm({ name: '', purpose: 'marketing' as TemplatePurpose, body: '', is_active: true });

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('smsMarketing.fields.name') },
    { key: 'purpose', label: t('smsMarketing.fields.purpose') },
    { key: 'body', label: t('smsMarketing.fields.body'), hideable: true },
    { key: 'segments', label: t('smsMarketing.fields.segments'), hideable: true },
    { key: 'actions', label: t('smsMarketing.common.actions'), srOnly: true },
]);
const purposeOptions = computed(() => props.purposes.map((value) => ({ value, label: t(`smsMarketing.status.purpose.${value}`) })));
const allowed = computed(() => (form.purpose === 'transactional' ? props.variables.transactional : props.variables.marketing));
const unknown = computed(() => unknownVariables(form.body, allowed.value));
// Marketing text always ends with the opt-out line, which the sample count here does not include; the server counts it.
const count = computed(() => countSegments(form.body));
const invalid = computed(() => form.name.trim() === '' || form.body.trim() === '' || unknown.value.length > 0);

function openForm(row: TemplateRow | null): void {
    editing.value = row;
    form.defaults({
        name: row?.name ?? '',
        purpose: row?.purpose ?? 'marketing',
        body: row?.body ?? '',
        is_active: row?.isActive ?? true,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit(): void {
    form.transform((data) => ({ ...data, name: data.name.trim() }));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (editing.value) {
        form.put(route('sms_marketing.templates.update', { template: editing.value.id }), options);
    } else {
        form.post(route('sms_marketing.templates.store'), options);
    }
}

function askRemove(row: TemplateRow): void {
    removing.value = row;
    removeOpen.value = true;
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    busy.value = true;
    router.delete(route('sms_marketing.templates.destroy', { template: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            removeOpen.value = false;
        },
    });
}

/** A variable as it is written in a text: two braces around the name. */
function token(name: string): string {
    return '{{' + name + '}}';
}

function excerpt(body: string): string {
    return body.length > 80 ? `${body.slice(0, 80)}…` : body;
}
</script>

<template>
    <PageHeader :eyebrow="t('smsMarketing.eyebrow')" :title="t('smsMarketing.templates.title')" :description="t('smsMarketing.templates.description')">
        <template #actions>
            <Button v-if="canManage" variant="primary" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('smsMarketing.templates.add') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <Card flush class="lg:col-span-2">
            <DataTable :columns="columns" :rows="templates" row-key="id" :caption="t('smsMarketing.templates.caption')">
                <template #cell-name="{ row }">
                    <span class="text-ink">{{ row.name }}</span>
                    <Badge v-if="!row.isActive" tone="neutral" class="ml-2">{{ t('smsMarketing.templates.inactive') }}</Badge>
                </template>
                <template #cell-purpose="{ row }"><SmsBadge kind="purpose" :value="row.purpose" /></template>
                <template #cell-body="{ row }"><span class="text-ink-muted">{{ excerpt(row.body) }}</span></template>
                <template #cell-segments="{ row }">
                    <span class="tabular text-ink-muted">{{ row.segments.segments }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <div v-if="canManage" class="flex justify-end gap-1">
                        <Button size="sm" variant="ghost" @click="openForm(row)">
                            <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                            {{ t('smsMarketing.common.edit') }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="askRemove(row)">
                            <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                            {{ t('smsMarketing.common.delete') }}
                        </Button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState :title="t('smsMarketing.templates.empty')" :description="t('smsMarketing.templates.emptyBody')" compact>
                        <template #icon><MessageSquareText /></template>
                        <Button v-if="canManage" size="sm" variant="primary" @click="openForm(null)">{{ t('smsMarketing.templates.add') }}</Button>
                    </EmptyState>
                </template>
            </DataTable>
        </Card>

        <Card :title="t('smsMarketing.templates.variablesTitle')" :description="t('smsMarketing.templates.variablesHint')">
            <ul class="grid gap-1.5 text-[0.8125rem]" data-testid="variables">
                <li v-for="name in variables.marketing" :key="name" class="flex items-center gap-2">
                    <code class="font-mono text-xs text-ink">{{ token(name) }}</code>
                    <span class="text-ink-muted">{{ t(`smsMarketing.variables.${name}`) }}</span>
                </li>
            </ul>
            <p class="mt-3 text-[0.8125rem] text-ink-muted">{{ t('smsMarketing.templates.oneTimeHint') }}</p>
            <ul class="mt-2 grid gap-1.5 text-[0.8125rem]">
                <li v-for="name in variables.transactional.filter((item) => !variables.marketing.includes(item))" :key="name" class="flex items-center gap-2">
                    <code class="font-mono text-xs text-ink">{{ token(name) }}</code>
                    <Badge tone="accent">{{ t('smsMarketing.status.purpose.transactional') }}</Badge>
                </li>
            </ul>
        </Card>
    </div>

    <SmsFormDialog
        v-model:open="open"
        :title="editing ? t('smsMarketing.templates.edit') : t('smsMarketing.templates.add')"
        :submit-label="t('smsMarketing.common.save')"
        :processing="form.processing"
        :disabled="invalid"
        size="lg"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('smsMarketing.fields.name')" maxlength="120" :error="form.errors.name" required autofocus />
        <Select
            v-model="form.purpose"
            :options="purposeOptions"
            :label="t('smsMarketing.fields.purpose')"
            :hint="t('smsMarketing.templates.purposeHint')"
            :error="form.errors.purpose"
        />
        <Textarea
            v-model="form.body"
            :label="t('smsMarketing.fields.body')"
            :rows="5"
            :maxlength="maxBody"
            :error="form.errors.body ?? (unknown.length > 0 ? t('smsMarketing.form.unknownVariables', { names: unknown.join(', ') }) : undefined)"
            required
        />
        <SegmentMeter :count="count" />
        <Checkbox v-model="form.is_active" :label="t('smsMarketing.templates.active')" :description="t('smsMarketing.templates.activeHint')" />
    </SmsFormDialog>

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('smsMarketing.templates.deleteTitle')"
        :description="t('smsMarketing.templates.deleteBody', { name: removing?.name ?? '' })"
        :confirm-label="t('smsMarketing.common.delete')"
        :loading="busy"
        @confirm="remove"
    />
</template>

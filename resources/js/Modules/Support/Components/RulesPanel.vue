<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ListFilter, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { AgentRow, CategoryRow, CustomerOption, QueueRow, RuleRow, TicketChannel, TicketPriority } from './types';

const props = defineProps<{
    rules: RuleRow[];
    queues: QueueRow[];
    agents: AgentRow[];
    categories: CategoryRow[];
    companies: CustomerOption[];
    priorities: TicketPriority[];
    channels: TicketChannel[];
}>();

const { t } = useI18n();

const open = ref(false);
const editing = ref<RuleRow | null>(null);
const removing = ref<RuleRow | null>(null);
const removePending = ref(false);

const form = useForm({
    name: '',
    position: '0',
    is_active: true,
    match_channel: '',
    match_priority: '',
    match_category_id: '',
    match_company_id: '',
    match_keyword: '',
    queue_id: '',
    assign_agent_id: '',
    set_priority: '',
});

const channelOptions = computed(() => [{ value: '', label: t('support.rules.anyChannel') }, ...props.channels.map((value) => ({ value, label: t(`support.channel.${value}`) }))]);
const priorityOptions = computed(() => [{ value: '', label: t('support.rules.anyPriority') }, ...props.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) }))]);
const setPriorityOptions = computed(() => [{ value: '', label: t('support.rules.keepPriority') }, ...props.priorities.map((value) => ({ value, label: t(`support.priority.${value}`) }))]);
const categoryOptions = computed(() => [{ value: '', label: t('support.rules.anyCategory') }, ...props.categories.map((item) => ({ value: item.id, label: item.name }))]);
const companyOptions = computed(() => [{ value: '', label: t('support.rules.anyCompany') }, ...props.companies.map((item) => ({ value: item.id, label: item.name }))]);
const queueOptions = computed(() => [{ value: '', label: t('support.rules.chooseQueue') }, ...props.queues.map((item) => ({ value: item.id, label: item.name }))]);
const agentOptions = computed(() => [{ value: '', label: t('support.rules.noAgent') }, ...props.agents.map((item) => ({ value: item.id, label: item.name }))]);

const queueName = (id: string): string => props.queues.find((queue) => queue.id === id)?.name ?? id;

/** One line saying what a rule matches. */
function conditions(rule: RuleRow): string {
    const parts: string[] = [];

    if (rule.matchChannel) {
        parts.push(t('support.rules.ifChannel', { channel: t(`support.channel.${rule.matchChannel}`) }));
    }

    if (rule.matchPriority) {
        parts.push(t('support.rules.ifPriority', { priority: t(`support.priority.${rule.matchPriority}`) }));
    }

    if (rule.matchCategoryId) {
        parts.push(t('support.rules.ifCategory', { category: props.categories.find((item) => item.id === rule.matchCategoryId)?.name ?? '' }));
    }

    if (rule.matchCompanyId) {
        parts.push(t('support.rules.ifCompany', { company: props.companies.find((item) => item.id === rule.matchCompanyId)?.name ?? '' }));
    }

    if (rule.matchKeyword) {
        parts.push(t('support.rules.ifKeyword', { keyword: rule.matchKeyword }));
    }

    return parts.length ? parts.join(', ') : t('support.rules.everyTicket');
}

function openForm(rule: RuleRow | null): void {
    editing.value = rule;
    form.defaults({
        name: rule?.name ?? '',
        position: String(rule?.position ?? 0),
        is_active: rule?.isActive ?? true,
        match_channel: rule?.matchChannel ?? '',
        match_priority: rule?.matchPriority ?? '',
        match_category_id: rule?.matchCategoryId ?? '',
        match_company_id: rule?.matchCompanyId ?? '',
        match_keyword: rule?.matchKeyword ?? '',
        queue_id: rule?.queueId ?? '',
        assign_agent_id: rule?.assignAgentId ?? '',
        set_priority: rule?.setPriority ?? '',
    }).reset();
    form.clearErrors();
    open.value = true;
}

function save(): void {
    form.transform((data) => {
        const nullable = (value: string): string | null => (value === '' ? null : value);

        return {
            ...data,
            position: Number(data.position || 0),
            match_channel: nullable(data.match_channel),
            match_priority: nullable(data.match_priority),
            match_category_id: nullable(data.match_category_id),
            match_company_id: nullable(data.match_company_id),
            match_keyword: nullable(data.match_keyword.trim()),
            assign_agent_id: nullable(data.assign_agent_id),
            set_priority: nullable(data.set_priority),
        };
    });
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        form.put(route('support.rules.update', { rule: editing.value.id }), options);
    } else {
        form.post(route('support.rules.store'), options);
    }
}

function remove(): void {
    if (!removing.value) {
        return;
    }

    removePending.value = true;
    router.delete(route('support.rules.destroy', { rule: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
        },
        onFinish: () => {
            removePending.value = false;
        },
    });
}
</script>

<template>
    <Card :title="t('support.rules.title')" :description="t('support.rules.description')" flush>
        <template #actions>
            <Button size="sm" variant="primary" :disabled="queues.length === 0" @click="openForm(null)">
                <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                {{ t('support.rules.add') }}
            </Button>
        </template>
        <EmptyState v-if="rules.length === 0" :title="t('support.rules.empty')" :description="t(queues.length ? 'support.rules.emptyBody' : 'support.rules.needQueue')" compact>
            <template #icon><ListFilter /></template>
        </EmptyState>
        <ol v-else class="divide-y divide-line" :aria-label="t('support.rules.title')">
            <li v-for="rule in rules" :key="rule.id" class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                        <span class="tabular text-ink-subtle">{{ rule.position }}</span>
                        {{ rule.name }}
                        <Badge v-if="!rule.isActive" tone="neutral">{{ t('support.common.inactive') }}</Badge>
                    </p>
                    <p class="mt-1 text-sm text-ink-muted wrap-anywhere">
                        {{ t('support.rules.summary', { conditions: conditions(rule), queue: queueName(rule.queueId) }) }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <Button icon size="sm" variant="ghost" :aria-label="t('support.queues.editFor', { name: rule.name })" @click="openForm(rule)">
                        <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                    </Button>
                    <Button icon size="sm" variant="ghost" :aria-label="t('support.rules.deleteFor', { name: rule.name })" @click="removing = rule">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                    </Button>
                </div>
            </li>
        </ol>
    </Card>

    <Modal v-model:open="open" size="lg" :title="t(editing ? 'support.rules.edit' : 'support.rules.add')" :persistent="form.processing">
        <form id="support-rule-form" class="grid gap-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-3">
                <Input v-model="form.name" class="sm:col-span-2" :label="t('support.fields.name')" :error="form.errors.name" required maxlength="120" />
                <Input v-model="form.position" type="number" min="0" max="1000" :label="t('support.rules.position')" :hint="t('support.rules.positionHint')" :error="form.errors.position" />
            </div>
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium text-ink">{{ t('support.rules.when') }}</legend>
                <Select v-model="form.match_channel" :options="channelOptions" :label="t('support.fields.channel')" :error="form.errors.match_channel" />
                <Select v-model="form.match_priority" :options="priorityOptions" :label="t('support.fields.priority')" :error="form.errors.match_priority" />
                <Select v-model="form.match_category_id" :options="categoryOptions" :label="t('support.fields.category')" :error="form.errors.match_category_id" />
                <Select v-model="form.match_company_id" :options="companyOptions" :label="t('support.fields.company')" :error="form.errors.match_company_id" />
                <Input v-model="form.match_keyword" :label="t('support.rules.keyword')" :hint="t('support.rules.keywordHint')" :error="form.errors.match_keyword" optional maxlength="80" />
            </fieldset>
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium text-ink">{{ t('support.rules.then') }}</legend>
                <Select v-model="form.queue_id" :options="queueOptions" :label="t('support.fields.queue')" :error="form.errors.queue_id" />
                <Select v-model="form.assign_agent_id" :options="agentOptions" :label="t('support.rules.assignTo')" :hint="t('support.rules.assignToHint')" :error="form.errors.assign_agent_id" />
                <Select v-model="form.set_priority" :options="setPriorityOptions" :label="t('support.rules.setPriority')" :error="form.errors.set_priority" />
            </fieldset>
            <Checkbox v-model="form.is_active" :label="t('support.rules.active')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-rule-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>

    <ConfirmDialog
        :open="removing !== null"
        :title="t('support.rules.deleteTitle')"
        :description="t('support.rules.deleteBody', { name: removing?.name ?? '' })"
        :confirm-label="t('support.common.delete')"
        :loading="removePending"
        @update:open="!$event && !removePending && (removing = null)"
        @confirm="remove"
    />
</template>

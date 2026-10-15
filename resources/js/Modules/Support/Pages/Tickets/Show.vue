<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, GitMerge, Pencil, Scissors, Send, UserCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import MergeDialog from '../../Components/MergeDialog.vue';
import MessageThread from '../../Components/MessageThread.vue';
import ReplyComposer from '../../Components/ReplyComposer.vue';
import SlaClock from '../../Components/SlaClock.vue';
import SplitDialog from '../../Components/SplitDialog.vue';
import SupportNav from '../../Components/SupportNav.vue';
import TicketBadges from '../../Components/TicketBadges.vue';
import TicketEditDialog from '../../Components/TicketEditDialog.vue';
import TicketTimeline from '../../Components/TicketTimeline.vue';
import { isFinished, ticketClocks } from '../../Components/supportFormat';
import type {
    MessageRow,
    Option,
    SurveyRow,
    TicketAbilities,
    TicketDetail,
    TicketEventRow,
    TicketFormOptions,
    TicketReference,
    TicketStatus,
} from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    ticket: TicketDetail;
    messages: MessageRow[];
    events: TicketEventRow[];
    surveys: SurveyRow[];
    abilities: TicketAbilities;
    options: TicketFormOptions & { tags: string[]; statusTargets: TicketStatus[]; maxAttachments: number };
    canned: Option[];
    mergeCandidates: TicketReference[];
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();
const now = Date.now();

const merged = computed(() => props.ticket.status === 'merged');
const clocks = computed(() => ticketClocks(props.ticket, now));
const myMembershipId = computed(() => page.props.auth?.membership?.id ?? null);
const assignedToMe = computed(() => myMembershipId.value !== null && props.ticket.assignee?.id === myMembershipId.value);

// The single-use survey link arrives once, as a flash, and is not kept anywhere.
const invitation = computed(() => {
    const flashed = page.flash?.surveyInvitation ?? null;

    return flashed !== null && flashed.ticketId === props.ticket.id ? flashed : null;
});
const copied = ref(false);
const copyFailed = ref(false);

async function copyInvitation(): Promise<void> {
    copied.value = false;
    copyFailed.value = false;

    try {
        if (!invitation.value || !navigator.clipboard) {
            throw new Error('unavailable');
        }

        await navigator.clipboard.writeText(invitation.value.url);
        copied.value = true;
    } catch {
        copyFailed.value = true;
    }
}

// Status change.
function allowed(target: TicketStatus): boolean {
    if (target === 'resolved' || target === 'closed') {
        return props.abilities.resolve;
    }

    return isFinished(props.ticket.status) ? props.abilities.reopen : true;
}

const statusForm = useForm({ status: '' });
const statusOptions = computed(() => [
    { value: '', label: t('support.status.choose') },
    ...props.options.statusTargets.filter((target) => target !== props.ticket.status && allowed(target)).map((value) => ({ value, label: t(`support.status.${value}`) })),
]);

function changeStatus(): void {
    if (statusForm.status === '') {
        return;
    }

    statusForm.post(route('support.tickets.status', { ticket: props.ticket.id }), {
        preserveScroll: true,
        onSuccess: () => statusForm.reset(),
    });
}

// Assignment.
const assignForm = useForm({ assignee_id: '' });
const assigneeOptions = computed(() => [
    { value: '', label: t('support.assign.choose') },
    ...props.options.agents
        .filter((agent) => agent.id !== props.ticket.assignee?.id)
        .map((agent) => ({ value: agent.id, label: agent.isAvailable ? agent.name : t('support.assign.unavailable', { name: agent.name }) })),
]);
const canUnassign = computed(() => props.ticket.assignee !== null && (props.abilities.assignOthers || assignedToMe.value));
const canAuto = computed(() => props.abilities.assignOthers && props.ticket.queue?.id !== undefined);
const assignError = computed(() => assignForm.errors.assignee_id ?? (assignForm.errors as Record<string, string | undefined>).ticket);

function claim(): void {
    router.post(route('support.tickets.claim', { ticket: props.ticket.id }), {}, { preserveScroll: true });
}

function assign(): void {
    if (assignForm.assignee_id === '') {
        return;
    }

    assignForm.post(route('support.tickets.assign', { ticket: props.ticket.id }), { preserveScroll: true, onSuccess: () => assignForm.reset() });
}

function unassign(): void {
    router.post(route('support.tickets.assign', { ticket: props.ticket.id }), { assignee_id: null }, { preserveScroll: true });
}

function autoAssign(): void {
    router.post(route('support.tickets.assign', { ticket: props.ticket.id }), { auto: true }, { preserveScroll: true });
}

// Survey.
const surveyPending = ref(false);

function requestSurvey(): void {
    surveyPending.value = true;
    router.post(route('support.tickets.survey', { ticket: props.ticket.id }), {}, { preserveScroll: true, onFinish: () => (surveyPending.value = false) });
}

// Dialogs and split mode.
const editOpen = ref(false);
const mergeOpen = ref(false);
const splitOpen = ref(false);
const splitMode = ref(false);
const selectedMessages = ref<string[]>([]);

function toggleSplitMode(): void {
    splitMode.value = !splitMode.value;
    selectedMessages.value = [];
}

const canSplitNow = computed(() => selectedMessages.value.length > 0 && selectedMessages.value.length < props.messages.length);

const requesterLabel = computed(() => props.ticket.requester.name ?? props.ticket.requesterEmail ?? t('support.common.none'));
</script>

<template>
    <PageHeader :eyebrow="ticket.number" :title="ticket.subject" :description="t('support.show.openedBy', { who: requesterLabel, time: format.date(ticket.createdAt, 'datetime') })">
        <template #actions>
            <Button v-if="abilities.edit" size="sm" @click="editOpen = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('support.common.edit') }}
            </Button>
            <Button v-if="abilities.merge" size="sm" @click="mergeOpen = true">
                <template #icon><GitMerge class="size-4" aria-hidden="true" /></template>
                {{ t('support.merge.action') }}
            </Button>
            <Button v-if="abilities.split && messages.length > 1" size="sm" :variant="splitMode ? 'primary' : 'secondary'" @click="toggleSplitMode">
                <template #icon><Scissors class="size-4" aria-hidden="true" /></template>
                {{ t(splitMode ? 'support.split.cancel' : 'support.split.action') }}
            </Button>
        </template>
    </PageHeader>
    <SupportNav current="tickets" />

    <div v-if="merged" class="glass mb-3 rounded-card border-l-4 border-l-info px-5 py-3" role="status">
        <p class="text-sm text-ink">
            {{ t('support.show.mergedNotice') }}
            <Link v-if="ticket.mergedInto" :href="route('support.tickets.show', { ticket: ticket.mergedInto.id })" class="font-medium text-brand-text underline">
                {{ ticket.mergedInto.number }}
            </Link>
        </p>
    </div>

    <div v-if="invitation" class="glass mb-3 rounded-card border-l-4 border-l-brand px-5 py-4" role="status">
        <p class="text-sm font-medium text-ink">{{ t('support.survey.linkTitle') }}</p>
        <p class="mt-1 text-sm text-ink-muted">{{ t('support.survey.linkBody') }}</p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
            <Input :model-value="invitation.url" readonly class="min-w-56 flex-1" :label="t('support.survey.link')" mono />
            <Button size="sm" @click="copyInvitation">
                <template #icon><Copy class="size-4" aria-hidden="true" /></template>
                {{ t(copied ? 'support.common.copied' : 'support.common.copy') }}
            </Button>
        </div>
        <p v-if="copyFailed" class="mt-2 text-sm text-danger" role="alert">{{ t('support.survey.copyFailed') }}</p>
    </div>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card :title="t('support.thread.title')">
                <div v-if="splitMode" class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-control bg-hover px-3 py-2" role="status">
                    <p class="text-sm text-ink">{{ t('support.split.selected', { count: format.number(selectedMessages.length), total: format.number(messages.length) }) }}</p>
                    <Button size="sm" variant="primary" :disabled="!canSplitNow" @click="splitOpen = true">{{ t('support.split.continue') }}</Button>
                </div>
                <MessageThread v-model:selected="selectedMessages" :ticket-id="ticket.id" :messages="messages" :selectable="splitMode" />
            </Card>

            <ReplyComposer
                v-if="abilities.reply"
                :ticket-id="ticket.id"
                :status="ticket.status"
                :abilities="abilities"
                :status-targets="options.statusTargets"
                :canned="canned"
                :max-attachments="options.maxAttachments"
            />
            <p v-else-if="!merged" class="text-sm text-ink-muted">{{ t('support.show.readOnly') }}</p>
        </div>

        <div class="grid content-start gap-3">
            <Card :title="t('support.show.details')">
                <dl class="grid gap-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <TicketBadges kind="status" :value="ticket.status" />
                        <TicketBadges kind="priority" :value="ticket.priority" />
                        <Badge>{{ t(`support.channel.${ticket.channel}`) }}</Badge>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('support.fields.requester') }}</dt>
                        <dd class="mt-1 text-ink wrap-anywhere">
                            {{ requesterLabel }}
                            <span v-if="ticket.requester.email" class="block text-xs text-ink-subtle">{{ ticket.requester.email }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('support.fields.assignee') }}</dt>
                        <dd class="mt-1 text-ink">{{ ticket.assignee?.name ?? t('support.tickets.unassigned') }}</dd>
                    </div>
                    <div v-if="ticket.queue">
                        <dt class="eyebrow">{{ t('support.fields.queue') }}</dt>
                        <dd class="mt-1 text-ink">{{ ticket.queue.name }}</dd>
                    </div>
                    <div v-if="ticket.category">
                        <dt class="eyebrow">{{ t('support.fields.category') }}</dt>
                        <dd class="mt-1 text-ink">{{ ticket.category.name }}</dd>
                    </div>
                    <div v-if="ticket.department || ticket.branch">
                        <dt class="eyebrow">{{ t('support.show.team') }}</dt>
                        <dd class="mt-1 text-ink">{{ [ticket.department?.name, ticket.branch?.name].filter(Boolean).join(' / ') }}</dd>
                    </div>
                    <div v-if="ticket.tags.length">
                        <dt class="eyebrow">{{ t('support.fields.tags') }}</dt>
                        <dd class="mt-1 flex flex-wrap gap-1">
                            <Badge v-for="tag in ticket.tags" :key="tag" tone="neutral">#{{ tag }}</Badge>
                        </dd>
                    </div>
                    <div v-if="ticket.createdBy">
                        <dt class="eyebrow">{{ t('support.show.createdBy') }}</dt>
                        <dd class="mt-1 text-ink">{{ ticket.createdBy }}</dd>
                    </div>
                    <div v-if="ticket.reopenedCount > 0">
                        <dt class="eyebrow">{{ t('support.show.reopened') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ format.number(ticket.reopenedCount) }}</dd>
                    </div>
                </dl>
            </Card>

            <Card :title="t('support.sla.title')">
                <div class="grid gap-3">
                    <p v-if="ticket.slaPolicy" class="text-sm text-ink-muted">{{ t('support.sla.policy', { name: ticket.slaPolicy.name }) }}</p>
                    <p v-else class="text-sm text-ink-muted">{{ t('support.sla.noPolicy') }}</p>
                    <div class="grid gap-2">
                        <SlaClock :label="t('support.sla.firstResponse')" :state="clocks.firstResponse.state" :due-at="clocks.firstResponse.dueAt" :now="now" />
                        <p v-if="clocks.firstResponse.dueAt" class="tabular text-xs text-ink-subtle">{{ t('support.sla.due', { time: format.date(clocks.firstResponse.dueAt, 'datetime') }) }}</p>
                    </div>
                    <div class="grid gap-2">
                        <SlaClock :label="t('support.sla.resolution')" :state="clocks.resolution.state" :due-at="clocks.resolution.dueAt" :now="now" />
                        <p v-if="clocks.resolution.dueAt" class="tabular text-xs text-ink-subtle">{{ t('support.sla.due', { time: format.date(clocks.resolution.dueAt, 'datetime') }) }}</p>
                    </div>
                    <p v-if="ticket.slaPaused" class="text-xs text-ink-muted">{{ t('support.sla.pausedNote') }}</p>
                </div>
            </Card>

            <Card v-if="abilities.reply && statusOptions.length > 1" :title="t('support.status.title')">
                <form class="grid gap-3" @submit.prevent="changeStatus">
                    <Select v-model="statusForm.status" :options="statusOptions" :label="t('support.status.move')" :error="statusForm.errors.status" />
                    <Button type="submit" size="sm" variant="primary" :loading="statusForm.processing" :disabled="statusForm.status === ''">{{ t('support.status.apply') }}</Button>
                </form>
            </Card>

            <Card v-if="abilities.assign" :title="t('support.assign.title')">
                <div class="grid gap-3">
                    <Button v-if="!assignedToMe" size="sm" variant="primary" @click="claim">
                        <template #icon><UserCheck class="size-4" aria-hidden="true" /></template>
                        {{ t('support.assign.claim') }}
                    </Button>
                    <form v-if="abilities.assignOthers" class="grid gap-2" @submit.prevent="assign">
                        <Select v-model="assignForm.assignee_id" :options="assigneeOptions" :label="t('support.assign.to')" :error="assignError" />
                        <Button type="submit" size="sm" :loading="assignForm.processing" :disabled="assignForm.assignee_id === ''">{{ t('support.assign.apply') }}</Button>
                    </form>
                    <Button v-if="canAuto" size="sm" variant="ghost" @click="autoAssign">{{ t('support.assign.auto') }}</Button>
                    <Button v-if="canUnassign" size="sm" variant="ghost" @click="unassign">{{ t('support.assign.unassign') }}</Button>
                </div>
            </Card>

            <Card v-if="ticket.mergedTickets.length || ticket.splitTickets.length || ticket.splitFrom || ticket.fromChat" :title="t('support.show.related')">
                <ul class="grid gap-2 text-sm">
                    <li v-if="ticket.fromChat">
                        <Link :href="route('support.live_chat.index', { open: ticket.fromChat, state: 'all' })" class="font-medium text-brand-text underline">{{ t('support.show.fromChat') }}</Link>
                    </li>
                    <li v-if="ticket.splitFrom">
                        <span class="text-ink-subtle">{{ t('support.show.splitFrom') }}</span>
                        <Link :href="route('support.tickets.show', { ticket: ticket.splitFrom.id })" class="ms-1 font-medium text-brand-text underline">{{ ticket.splitFrom.number }}</Link>
                    </li>
                    <li v-for="item in ticket.mergedTickets" :key="item.id">
                        <span class="text-ink-subtle">{{ t('support.show.mergedFrom') }}</span>
                        <Link :href="route('support.tickets.show', { ticket: item.id })" class="ms-1 font-medium text-brand-text underline">{{ item.number }}</Link>
                    </li>
                    <li v-for="item in ticket.splitTickets" :key="item.id">
                        <span class="text-ink-subtle">{{ t('support.show.splitTo') }}</span>
                        <Link :href="route('support.tickets.show', { ticket: item.id })" class="ms-1 font-medium text-brand-text underline">{{ item.number }}</Link>
                    </li>
                </ul>
            </Card>

            <Card v-if="surveys.length || abilities.survey" :title="t('support.survey.title')">
                <div class="grid gap-3">
                    <ul v-if="surveys.length" class="grid gap-2 text-sm">
                        <li v-for="survey in surveys" :key="survey.id" class="grid gap-1 rounded-control bg-hover px-3 py-2">
                            <span class="flex flex-wrap items-center gap-2">
                                <Badge :tone="survey.status === 'answered' ? 'ok' : survey.status === 'pending' ? 'info' : 'neutral'">{{ t(`support.surveyStatus.${survey.status}`) }}</Badge>
                                <span v-if="survey.rating !== null" class="tabular font-medium text-ink">{{ t('support.survey.rating', { rating: format.number(survey.rating) }) }}</span>
                            </span>
                            <p v-if="survey.comment" class="whitespace-pre-wrap wrap-anywhere text-ink">{{ survey.comment }}</p>
                            <span class="tabular text-xs text-ink-subtle">
                                {{ survey.answeredAt ? t('support.survey.answeredAt', { time: format.date(survey.answeredAt, 'datetime') }) : t('support.survey.expiresAt', { time: format.date(survey.expiresAt, 'datetime') }) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-ink-muted">{{ t('support.survey.none') }}</p>
                    <Button v-if="abilities.survey" size="sm" :loading="surveyPending" @click="requestSurvey">
                        <template #icon><Send class="size-4" aria-hidden="true" /></template>
                        {{ t('support.survey.request') }}
                    </Button>
                </div>
            </Card>

            <Card :title="t('support.timeline.title')">
                <TicketTimeline :events="events" />
            </Card>
        </div>
    </div>

    <TicketEditDialog v-if="abilities.edit" v-model:open="editOpen" :ticket="ticket" :priorities="options.priorities" :queues="options.queues" :categories="options.categories" :contacts="options.contacts" :companies="options.companies" />
    <MergeDialog v-if="abilities.merge" v-model:open="mergeOpen" :ticket-id="ticket.id" :number="ticket.number" :candidates="mergeCandidates" />
    <SplitDialog v-if="abilities.split" v-model:open="splitOpen" :ticket-id="ticket.id" :number="ticket.number" :message-ids="selectedMessages" :default-subject="ticket.subject" />
</template>

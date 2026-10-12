<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { CalendarPlus, ChevronDown, Download, HandCoins, RotateCcw, UserCheck, UserX } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import HireDialog from '../../Components/HireDialog.vue';
import InterviewDialog from '../../Components/InterviewDialog.vue';
import NoteDialog from '../../Components/NoteDialog.vue';
import OfferDialog from '../../Components/OfferDialog.vue';
import RejectDialog from '../../Components/RejectDialog.vue';
import ReopenDialog from '../../Components/ReopenDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type {
    ApplicationRow,
    CandidateDocument,
    HistoryRow,
    InterviewRow,
    NamedRef,
    Offer,
    OfferStatus,
    OpeningRow,
    PersonOption,
    Stage,
    TalentNote,
} from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    application: ApplicationRow;
    opening: OpeningRow;
    history: HistoryRow[];
    interviews: InterviewRow[];
    notes: TalentNote[];
    documents: CandidateDocument[];
    offer: Offer | null;
    offers: Offer[];
    stages: Stage[];
    rejectionReasons: NamedRef[];
    abilities: {
        move: boolean;
        reject: boolean;
        withdraw: boolean;
        reopen: boolean;
        hire: boolean;
        addNote: boolean;
        schedule: boolean;
        manageOffer: boolean;
        viewCandidate: boolean;
    };
    offerStatuses: OfferStatus[];
    employees?: PersonOption[];
    timezone?: string;
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const rejecting = ref(false);
const withdrawing = ref(false);
const reopening = ref(false);
const hiring = ref(false);
const scheduling = ref(false);
const offerOpen = ref(false);
const editingOffer = ref<Offer | null>(null);
const busy = ref(false);

const noteForm = useForm({ body: '' });

const candidateName = computed(() => props.application.candidate.name ?? t('talent.candidates.erased'));
const moveTargets = computed(() => props.stages.filter((stage) => stage.type === 'active' && stage.id !== props.application.stage.id));
const offerNext: Record<string, OfferStatus[]> = { draft: ['sent', 'withdrawn'], sent: ['accepted', 'declined', 'withdrawn'] };
const hasActions = computed(() => Object.values(props.abilities).some((value) => value) && props.application.status !== 'hired');

function moveTo(stage: Stage): void {
    busy.value = true;
    router.post(route('talent.applications.move', { application: props.application.id }), { stage_id: stage.id }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

function addNote(): void {
    noteForm.transform((data) => ({ body: data.body.trim(), application_id: props.application.id }))
        .post(route('talent.applications.notes.store', { application: props.application.id }), {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        });
}

function openOffer(offer: Offer | null): void {
    editingOffer.value = offer;
    offerOpen.value = true;
}

function setOfferStatus(offer: Offer, status: OfferStatus): void {
    router.post(route('talent.offers.status', { offer: offer.id }), { status }, { preserveScroll: true });
}

function historyText(row: HistoryRow): string {
    if (row.event === 'moved' || row.event === 'reopened') {
        return t(`talent.history.${row.event}`, { from: row.from ?? t('talent.common.dash'), to: row.to ?? t('talent.common.dash') });
    }

    return t(`talent.history.${row.event}`);
}
</script>

<template>
    <PageHeader :eyebrow="opening.title" :title="candidateName" :description="application.candidate.headline ?? undefined">
        <template v-if="hasActions" #actions>
            <Dropdown v-if="abilities.move && moveTargets.length > 0">
                <template #trigger>
                    <Button variant="secondary" :loading="busy">
                        {{ t('talent.applications.moveTo') }}
                        <ChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                </template>
                <DropdownItem v-for="stage in moveTargets" :key="stage.id" @select="moveTo(stage)">{{ stage.name }}</DropdownItem>
            </Dropdown>
            <Button v-if="abilities.schedule" variant="secondary" @click="scheduling = true">
                <template #icon><CalendarPlus class="size-4" aria-hidden="true" /></template>
                {{ t('talent.interviews.schedule') }}
            </Button>
            <Button v-if="abilities.hire" variant="primary" @click="hiring = true">
                <template #icon><UserCheck class="size-4" aria-hidden="true" /></template>
                {{ t('talent.applications.hire') }}
            </Button>
            <Button v-if="abilities.reopen" variant="secondary" @click="reopening = true">
                <template #icon><RotateCcw class="size-4" aria-hidden="true" /></template>
                {{ t('talent.applications.reopen') }}
            </Button>
            <Button v-if="abilities.withdraw" variant="ghost" @click="withdrawing = true">{{ t('talent.applications.withdraw') }}</Button>
            <Button v-if="abilities.reject" variant="danger" @click="rejecting = true">
                <template #icon><UserX class="size-4" aria-hidden="true" /></template>
                {{ t('talent.applications.reject') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-8">
            <Card :title="t('talent.interviews.title')" flush>
                <EmptyState v-if="interviews.length === 0" :title="t('talent.applications.noInterviews')" compact class="p-5" />
                <ul v-else class="divide-y divide-line" data-testid="application-interviews">
                    <li v-for="interview in interviews" :key="interview.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                        <div class="min-w-0">
                            <Link :href="route('talent.interviews.show', { interview: interview.id })" class="text-sm text-ink hover:text-brand-text">
                                {{ interview.roundName }}
                            </Link>
                            <p class="tabular text-xs text-ink-subtle">
                                {{ format.date(interview.date) }} {{ talent.clock(interview.time) }} - {{ talent.duration(interview.durationMinutes) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <TalentBadge v-if="interview.outcome" kind="outcome" :value="interview.outcome" />
                            <TalentBadge kind="interview" :value="interview.status" />
                        </div>
                    </li>
                </ul>
            </Card>

            <Card :title="t('talent.history.title')">
                <ol class="grid gap-3" data-testid="application-history">
                    <li v-for="row in history" :key="row.id" class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                        <span class="text-ink">
                            {{ historyText(row) }}
                            <span v-if="row.note" class="text-ink-muted">- {{ row.note }}</span>
                        </span>
                        <span class="tabular text-xs text-ink-subtle">{{ format.date(row.at, 'datetime') }}</span>
                    </li>
                </ol>
            </Card>

            <Card :title="t('talent.notes.title')">
                <form v-if="abilities.addNote" class="mb-4 grid gap-2" @submit.prevent="addNote">
                    <Textarea v-model="noteForm.body" :label="t('talent.notes.add')" maxlength="5000" :error="noteForm.errors.body" />
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" variant="primary" :loading="noteForm.processing" :disabled="noteForm.body.trim() === ''">
                            {{ t('talent.notes.save') }}
                        </Button>
                    </div>
                </form>
                <EmptyState v-if="notes.length === 0" :title="t('talent.notes.empty')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="note in notes" :key="note.id" class="py-3 first:pt-0 last:pb-0">
                        <p class="text-sm whitespace-pre-line text-ink">{{ note.body }}</p>
                        <p class="mt-1 text-xs text-ink-subtle">{{ format.date(note.createdAt, 'datetime') }}</p>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-4">
            <Card :title="t('talent.common.details')">
                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.status') }}</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-2">
                            <TalentBadge kind="application" :value="application.status" />
                            <span class="text-ink-muted">{{ application.stage.name }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.fields.opening') }}</dt>
                        <dd class="mt-1">
                            <Link :href="route('talent.openings.show', { opening: opening.id })" class="text-ink hover:text-brand-text">{{ opening.title }}</Link>
                        </dd>
                    </div>
                    <div v-if="abilities.viewCandidate">
                        <dt class="eyebrow">{{ t('talent.fields.candidate') }}</dt>
                        <dd class="mt-1">
                            <Link :href="route('talent.candidates.show', { candidate: application.candidate.id })" class="text-ink hover:text-brand-text">{{ candidateName }}</Link>
                            <span v-if="application.candidate.email" class="block break-all text-xs text-ink-subtle">{{ application.candidate.email }}</span>
                            <span v-if="application.candidate.phone" class="block text-xs text-ink-subtle">{{ application.candidate.phone }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('talent.applications.appliedLabel') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(application.appliedOn) }}</dd>
                    </div>
                    <div v-if="application.source">
                        <dt class="eyebrow">{{ t('talent.fields.source') }}</dt>
                        <dd class="mt-1 text-ink">{{ application.source }}</dd>
                    </div>
                    <div v-if="application.startOn">
                        <dt class="eyebrow">{{ t('talent.fields.startOn') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(application.startOn) }}</dd>
                    </div>
                    <div v-if="application.rejectionNote">
                        <dt class="eyebrow">{{ t('talent.applications.rejectionNote') }}</dt>
                        <dd class="mt-1 text-ink">{{ application.rejectionNote }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="abilities.manageOffer || offers.length > 0" :title="t('talent.offers.title')" :description="t('talent.offers.trackingOnly')">
                <template v-if="abilities.manageOffer && offer === null" #actions>
                    <Button size="sm" variant="secondary" @click="openOffer(null)">
                        <template #icon><HandCoins class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.offers.add') }}
                    </Button>
                </template>
                <EmptyState v-if="offers.length === 0" :title="t('talent.offers.empty')" compact />
                <ul v-else class="grid gap-3" data-testid="offers">
                    <li v-for="item in offers" :key="item.id" class="glass-well grid gap-2 rounded-control p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="tabular text-sm font-medium text-ink">
                                {{ item.amount === null ? t('talent.common.dash') : format.money(item.amount, item.currency ?? undefined) }}
                            </span>
                            <TalentBadge kind="offer" :value="item.status" />
                        </div>
                        <p v-if="item.proposedStartOn" class="text-xs text-ink-subtle">{{ t('talent.offers.proposedStart') }}: {{ format.date(item.proposedStartOn) }}</p>
                        <p v-if="item.expiresOn" class="text-xs text-ink-subtle">{{ t('talent.offers.expires') }}: {{ format.date(item.expiresOn) }}</p>
                        <p v-if="item.notes" class="text-xs text-ink-muted">{{ item.notes }}</p>
                        <div v-if="abilities.manageOffer && item.id === offer?.id" class="flex flex-wrap gap-1">
                            <Button v-if="item.status === 'draft' || item.status === 'sent'" size="sm" variant="ghost" @click="openOffer(item)">{{ t('talent.common.edit') }}</Button>
                            <Button
                                v-for="next in offerNext[item.status] ?? []"
                                :key="next"
                                size="sm"
                                :variant="next === 'sent' || next === 'accepted' ? 'secondary' : 'ghost'"
                                @click="setOfferStatus(item, next)"
                            >
                                {{ t(`talent.offers.action.${next}`) }}
                            </Button>
                        </div>
                    </li>
                </ul>
                <p v-if="abilities.hire && offer !== null && offer.status !== 'accepted'" class="mt-3 text-xs text-warn" role="status">{{ t('talent.offers.mustBeAccepted') }}</p>
            </Card>

            <Card v-if="abilities.viewCandidate" :title="t('talent.documents.title')">
                <EmptyState v-if="documents.length === 0" :title="t('talent.documents.empty')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="document in documents" :key="document.id" class="flex items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-ink">{{ document.title }}</p>
                            <p class="text-xs text-ink-subtle">{{ t(`talent.status.document.${document.type}`) }} - {{ talent.size(document.size) }}</p>
                        </div>
                        <a
                            :href="route('talent.candidates.documents.download', { candidate: application.candidate.id, document: document.id })"
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-control text-ink-muted hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-brand"
                            :aria-label="t('talent.documents.download', { name: document.title })"
                        >
                            <Download class="size-4" aria-hidden="true" />
                        </a>
                    </li>
                </ul>
            </Card>
        </div>
    </div>

    <RejectDialog v-if="abilities.reject" v-model:open="rejecting" :application-id="application.id" :reasons="rejectionReasons" />
    <ReopenDialog v-if="abilities.reopen" v-model:open="reopening" :application-id="application.id" :stages="stages" />
    <HireDialog v-if="abilities.hire" v-model:open="hiring" :application-id="application.id" :proposed-start-on="offer?.proposedStartOn ?? application.startOn" />
    <NoteDialog
        v-if="abilities.withdraw"
        v-model:open="withdrawing"
        :url="route('talent.applications.withdraw', { application: application.id })"
        :title="t('talent.applications.withdrawTitle')"
        :description="t('talent.applications.withdrawHint')"
        :submit-label="t('talent.applications.withdraw')"
        danger
    />
    <InterviewDialog
        v-if="abilities.schedule"
        v-model:open="scheduling"
        :application-id="application.id"
        :interview="null"
        :employees="employees ?? []"
        :timezone="timezone ?? ''"
    />
    <OfferDialog v-if="abilities.manageOffer" v-model:open="offerOpen" :application-id="application.id" :offer="editingOffer" />
</template>

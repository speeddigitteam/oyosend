<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Download, FilePlus2, Pencil, ShieldAlert, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CandidateDialog from '../../Components/CandidateDialog.vue';
import DocumentDialog from '../../Components/DocumentDialog.vue';
import TalentBadge from '../../Components/TalentBadge.vue';
import { useTalentFormat } from '../../Components/talentFormat';
import type { ApplicationRow, CandidateDetail, CandidateDocument, ConsentBasis, DocumentType, TalentNote } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    candidate: CandidateDetail;
    applications: ApplicationRow[];
    documents: CandidateDocument[];
    notes: TalentNote[];
    duplicates: { id: string; name: string; email: string | null }[];
    consentBases: ConsentBasis[];
    documentTypes: DocumentType[];
    abilities: { edit: boolean; anonymize: boolean; addNote: boolean; uploadDocument: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const talent = useTalentFormat();

const editing = ref(false);
const uploading = ref(false);
const erasing = ref(false);
const removing = ref<CandidateDocument | null>(null);
const removeOpen = ref(false);
const confirmation = ref('');

const noteForm = useForm({ body: '' });
const displayName = computed(() => props.candidate.name ?? t('talent.candidates.erased'));
const expected = computed(() => {
    const { expectedAmount, currency } = props.candidate;

    return expectedAmount === null || currency === null ? null : format.money(expectedAmount, currency);
});
// The person types the candidate's own name (or the word shown) to confirm the irreversible erase.
const confirmWord = computed(() => t('talent.candidates.eraseWord'));
const confirmed = computed(() => confirmation.value.trim().toLowerCase() === confirmWord.value.toLowerCase());

function addNote(): void {
    noteForm.post(route('talent.candidates.notes.store', { candidate: props.candidate.id }), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

function anonymize(): void {
    router.post(route('talent.candidates.anonymize', { candidate: props.candidate.id }), {}, {
        preserveScroll: true,
        onSuccess: () => { erasing.value = false; confirmation.value = ''; },
    });
}

function askRemove(document: CandidateDocument): void {
    removing.value = document;
    removeOpen.value = true;
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    router.delete(route('talent.candidates.documents.destroy', { candidate: props.candidate.id, document: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => { removeOpen.value = false; removing.value = null; },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="displayName" :description="candidate.headline ?? undefined">
        <template v-if="abilities.edit || abilities.anonymize" #actions>
            <Button v-if="abilities.edit" variant="secondary" @click="editing = true">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('talent.common.edit') }}
            </Button>
            <Button v-if="abilities.anonymize" variant="danger" @click="erasing = true">
                <template #icon><ShieldAlert class="size-4" aria-hidden="true" /></template>
                {{ t('talent.candidates.erase') }}
            </Button>
        </template>
    </PageHeader>

    <Card v-if="candidate.isAnonymized" class="mb-3">
        <p class="text-sm text-ink" role="status" data-testid="erased-notice">{{ t('talent.candidates.erasedNotice', { date: format.date(candidate.anonymizedAt) }) }}</p>
    </Card>

    <Card v-if="duplicates.length > 0" class="mb-3">
        <p class="text-sm text-ink">{{ t('talent.candidates.sameEmail') }}</p>
        <ul class="mt-2 grid gap-1">
            <li v-for="match in duplicates" :key="match.id">
                <Link :href="route('talent.candidates.show', { candidate: match.id })" class="text-sm text-brand-text hover:underline">{{ match.name }}</Link>
            </li>
        </ul>
    </Card>

    <div class="grid gap-3 lg:grid-cols-12">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-7">
            <Card :title="t('talent.candidates.applicationsTitle')" flush>
                <EmptyState v-if="applications.length === 0" :title="t('talent.candidates.noApplications')" compact class="p-5" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="application in applications" :key="application.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                        <div class="min-w-0">
                            <Link :href="route('talent.applications.show', { application: application.id })" class="text-sm text-ink hover:text-brand-text">
                                {{ application.opening.title }}
                            </Link>
                            <p class="text-xs text-ink-subtle">{{ application.stage.name }} - {{ format.date(application.appliedOn) }}</p>
                        </div>
                        <TalentBadge kind="application" :value="application.status" />
                    </li>
                </ul>
            </Card>

            <Card :title="t('talent.notes.title')">
                <form v-if="abilities.addNote" class="mb-4 grid gap-2" @submit.prevent="addNote">
                    <Textarea v-model="noteForm.body" :label="t('talent.notes.add')" maxlength="5000" :error="noteForm.errors.body" />
                    <div class="flex justify-end">
                        <Button type="submit" size="sm" variant="primary" :loading="noteForm.processing" :disabled="noteForm.body.trim() === ''">{{ t('talent.notes.save') }}</Button>
                    </div>
                </form>
                <EmptyState v-if="notes.length === 0" :title="t('talent.notes.empty')" compact />
                <ul v-else class="divide-y divide-line" data-testid="candidate-notes">
                    <li v-for="note in notes" :key="note.id" class="py-3 first:pt-0 last:pb-0">
                        <p class="text-sm whitespace-pre-line text-ink">{{ note.body }}</p>
                        <p class="mt-1 text-xs text-ink-subtle">{{ format.date(note.createdAt, 'datetime') }}</p>
                    </li>
                </ul>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3 lg:col-span-5">
            <Card :title="t('talent.common.details')">
                <dl class="grid gap-3 text-sm">
                    <div v-if="candidate.email">
                        <dt class="eyebrow">{{ t('talent.fields.email') }}</dt>
                        <dd class="mt-1 break-all text-ink">{{ candidate.email }}</dd>
                    </div>
                    <div v-if="candidate.phone">
                        <dt class="eyebrow">{{ t('talent.fields.phone') }}</dt>
                        <dd class="mt-1 text-ink">{{ candidate.phone }}</dd>
                    </div>
                    <div v-if="candidate.currentCompany">
                        <dt class="eyebrow">{{ t('talent.fields.currentCompany') }}</dt>
                        <dd class="mt-1 text-ink">{{ candidate.currentCompany }}</dd>
                    </div>
                    <div v-if="candidate.location">
                        <dt class="eyebrow">{{ t('talent.fields.location') }}</dt>
                        <dd class="mt-1 text-ink">{{ candidate.location }}</dd>
                    </div>
                    <div v-if="candidate.source">
                        <dt class="eyebrow">{{ t('talent.fields.source') }}</dt>
                        <dd class="mt-1 text-ink">{{ candidate.source }}</dd>
                    </div>
                    <div v-if="expected">
                        <dt class="eyebrow">{{ t('talent.fields.expectedAmount') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ expected }}</dd>
                    </div>
                    <div v-if="!candidate.isAnonymized">
                        <dt class="eyebrow">{{ t('talent.fields.consentBasis') }}</dt>
                        <dd class="mt-1 text-ink">
                            {{ t(`talent.status.consent.${candidate.consentBasis}`) }}
                            <span v-if="candidate.consentGivenAt" class="text-ink-subtle">- {{ format.date(candidate.consentGivenAt) }}</span>
                        </dd>
                    </div>
                    <div v-if="candidate.retainUntil">
                        <dt class="eyebrow">{{ t('talent.fields.retainUntil') }}</dt>
                        <dd class="mt-1 text-ink">{{ format.date(candidate.retainUntil) }}</dd>
                    </div>
                </dl>
                <p v-if="candidate.summary" class="mt-4 border-t border-line pt-3 text-sm whitespace-pre-line text-ink">{{ candidate.summary }}</p>
            </Card>

            <Card :title="t('talent.documents.title')">
                <template v-if="abilities.uploadDocument" #actions>
                    <Button size="sm" variant="secondary" @click="uploading = true">
                        <template #icon><FilePlus2 class="size-4" aria-hidden="true" /></template>
                        {{ t('talent.documents.upload') }}
                    </Button>
                </template>
                <EmptyState v-if="documents.length === 0" :title="t('talent.documents.empty')" compact />
                <ul v-else class="divide-y divide-line" data-testid="candidate-documents">
                    <li v-for="document in documents" :key="document.id" class="flex items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-ink">{{ document.title }}</p>
                            <p class="text-xs text-ink-subtle">
                                {{ t(`talent.status.document.${document.type}`) }} - {{ talent.size(document.size) }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <a
                                :href="route('talent.candidates.documents.download', { candidate: candidate.id, document: document.id })"
                                class="inline-flex size-8 items-center justify-center rounded-control text-ink-muted hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-brand"
                                :aria-label="t('talent.documents.download', { name: document.title })"
                                data-testid="document-download"
                            >
                                <Download class="size-4" aria-hidden="true" />
                            </a>
                            <Button v-if="abilities.uploadDocument" size="sm" variant="ghost" @click="askRemove(document)">
                                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                                <span class="sr-only">{{ t('talent.documents.remove', { name: document.title }) }}</span>
                            </Button>
                        </div>
                    </li>
                </ul>
            </Card>

            <Badge v-if="candidate.isAnonymized" tone="neutral">{{ t('talent.candidates.erasedBadge') }}</Badge>
        </div>
    </div>

    <CandidateDialog v-if="abilities.edit" v-model:open="editing" :candidate="candidate" :consent-bases="consentBases" />
    <DocumentDialog v-if="abilities.uploadDocument" v-model:open="uploading" :candidate-id="candidate.id" :document-types="documentTypes" />

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="t('talent.documents.removeTitle')"
        :description="t('talent.documents.removeBody', { name: removing?.title ?? '' })"
        :confirm-label="t('talent.common.remove')"
        @confirm="remove"
    />

    <Modal v-model:open="erasing" size="sm" :title="t('talent.candidates.eraseTitle')" :description="t('talent.candidates.eraseBody')">
        <div class="grid gap-3">
            <p class="text-sm text-danger" role="alert">{{ t('talent.candidates.eraseWarning') }}</p>
            <Input v-model="confirmation" :label="t('talent.candidates.eraseConfirmLabel', { word: confirmWord })" autocomplete="off" />
        </div>
        <template #footer>
            <Button variant="ghost" @click="erasing = false">{{ t('talent.common.cancel') }}</Button>
            <Button variant="danger" :disabled="!confirmed" data-testid="erase-confirm" @click="anonymize">{{ t('talent.candidates.eraseSubmit') }}</Button>
        </template>
    </Modal>
</template>

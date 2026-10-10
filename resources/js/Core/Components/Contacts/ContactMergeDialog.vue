<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Check, GitMerge, SearchX, ShieldX } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import SegmentedControl, { type Segment } from '@/Core/Components/SegmentedControl.vue';
import { countryName } from '@/Core/lib/countries';
import { route } from '@/Core/lib/route';
import type { ContactDuplicateCandidate, ContactMergeField, ContactMergeFields, ContactMergeSource, ContactRow } from './types';

type Step = 'candidates' | 'fields' | 'confirm';
type SelectableSource = Exclude<ContactMergeSource, 'input'>;

interface ComparisonRow {
    key: ContactMergeField;
    label: string;
    canonical: string;
    duplicate: string;
    differs: boolean;
    multiline?: boolean;
}

const props = defineProps<{ contact: ContactRow | null }>();
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const page = usePage();

const step = ref<Step>('candidates');
const candidates = ref<ContactDuplicateCandidate[]>([]);
const selected = ref<ContactDuplicateCandidate | null>(null);
const loading = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
let request: AbortController | null = null;

const choices = reactive<Record<ContactMergeField, SelectableSource>>({
    first_name: 'canonical',
    last_name: 'canonical',
    email: 'canonical',
    phone: 'canonical',
    job_title: 'canonical',
    address: 'canonical',
    city: 'canonical',
    country: 'canonical',
    notes: 'canonical',
    company_id: 'canonical',
});

const MERGE_FIELDS = Object.keys(choices) as ContactMergeField[];

const sourceOptions = computed<Segment<'canonical' | 'duplicate'>[]>(() => [
    { value: 'canonical', label: t('contacts.people.merge.keepCurrent') },
    { value: 'duplicate', label: t('contacts.people.merge.useDuplicate') },
]);

const title = computed(() => {
    if (step.value === 'confirm') {
        return t('contacts.people.merge.confirmTitle');
    }

    if (step.value === 'fields') {
        return t('contacts.people.merge.chooseTitle');
    }

    return t('contacts.people.merge.title', { name: props.contact?.name ?? '' });
});

const description = computed(() => {
    if (step.value === 'confirm') {
        return t('contacts.people.merge.confirmDescription');
    }

    if (step.value === 'fields') {
        return t('contacts.people.merge.chooseDescription', { name: props.contact?.name ?? '' });
    }

    return t('contacts.people.merge.description');
});

function display(value: string | null | undefined): string {
    return value?.trim() || t('contacts.list.notRecorded');
}

function differs(canonical: string | null | undefined, duplicate: string | null | undefined): boolean {
    return (canonical?.trim() || '') !== (duplicate?.trim() || '');
}

const comparisons = computed<ComparisonRow[]>(() => {
    const contact = props.contact;
    const duplicate = selected.value;

    if (!contact || !duplicate) {
        return [];
    }

    const rows: ComparisonRow[] = [
        {
            key: 'first_name',
            label: t('contacts.fields.firstName'),
            canonical: display(contact.firstName),
            duplicate: display(duplicate.firstName),
            differs: differs(contact.firstName, duplicate.firstName),
        },
        {
            key: 'last_name',
            label: t('contacts.fields.lastName'),
            canonical: display(contact.lastName),
            duplicate: display(duplicate.lastName),
            differs: differs(contact.lastName, duplicate.lastName),
        },
        {
            key: 'email',
            label: t('contacts.fields.email'),
            canonical: display(contact.email),
            duplicate: display(duplicate.email),
            differs: differs(contact.email, duplicate.email),
        },
        {
            key: 'phone',
            label: t('contacts.fields.phone'),
            canonical: display(contact.phone),
            duplicate: display(duplicate.phone),
            differs: differs(contact.phone, duplicate.phone),
        },
        {
            key: 'job_title',
            label: t('contacts.fields.jobTitle'),
            canonical: display(contact.jobTitle),
            duplicate: display(duplicate.jobTitle),
            differs: differs(contact.jobTitle, duplicate.jobTitle),
        },
        {
            key: 'address',
            label: t('contacts.fields.address'),
            canonical: display(contact.address),
            duplicate: display(duplicate.address),
            differs: differs(contact.address, duplicate.address),
            multiline: true,
        },
        {
            key: 'city',
            label: t('contacts.fields.city'),
            canonical: display(contact.city),
            duplicate: display(duplicate.city),
            differs: differs(contact.city, duplicate.city),
        },
        {
            key: 'country',
            label: t('contacts.fields.country'),
            canonical: display(countryName(contact.country, page.props.app.locale)),
            duplicate: display(countryName(duplicate.country, page.props.app.locale)),
            differs: differs(contact.country, duplicate.country),
        },
        {
            key: 'notes',
            label: t('contacts.fields.notes'),
            canonical: display(contact.notes),
            duplicate: display(duplicate.notes),
            differs: differs(contact.notes, duplicate.notes),
            multiline: true,
        },
        {
            key: 'company_id',
            label: t('contacts.fields.company'),
            canonical: display(contact.company?.name),
            duplicate: display(duplicate.company?.name),
            differs: contact.company?.id !== duplicate.company?.id,
        },
    ];

    return rows.filter((row) => row.differs);
});

function resetChoices(): void {
    for (const field of MERGE_FIELDS) {
        choices[field] = 'canonical';
    }
}

function reset(): void {
    request?.abort();
    request = null;
    step.value = 'candidates';
    candidates.value = [];
    selected.value = null;
    loading.value = false;
    submitting.value = false;
    error.value = null;
    resetChoices();
}

async function loadCandidates(): Promise<void> {
    const contact = props.contact;

    if (!contact) {
        return;
    }

    request?.abort();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(route('core.contacts.duplicates.show', { contact: contact.id }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(t('contacts.people.merge.loadError'));
        }

        const body = (await response.json()) as { data?: ContactDuplicateCandidate[] };
        candidates.value = Array.isArray(body.data) ? body.data : [];
    } catch (reason: unknown) {
        if (!(reason instanceof DOMException && reason.name === 'AbortError')) {
            error.value = reason instanceof Error ? reason.message : t('contacts.people.merge.loadError');
        }
    } finally {
        if (request === controller) {
            loading.value = false;
            request = null;
        }
    }
}

watch(
    () => open.value,
    (isOpen) => {
        if (isOpen) {
            reset();
            void loadCandidates();
        } else {
            request?.abort();
        }
    },
);

function review(candidate: ContactDuplicateCandidate): void {
    if (!candidate.mergeable) {
        return;
    }

    selected.value = candidate;
    resetChoices();
    error.value = null;
    step.value = 'fields';
}

function chosenValue(row: ComparisonRow): string {
    return choices[row.key] === 'duplicate' ? row.duplicate : row.canonical;
}

function mergeFields(): ContactMergeFields {
    const fields: ContactMergeFields = {};

    for (const row of comparisons.value) {
        fields[row.key] = { source: choices[row.key] };
    }

    return fields;
}

function submit(): void {
    const contact = props.contact;
    const duplicate = selected.value;

    if (!contact || !duplicate || !duplicate.mergeable) {
        return;
    }

    submitting.value = true;
    error.value = null;

    router.post(
        route('core.contacts.merge', { contact: contact.id }),
        { duplicate_id: duplicate.id, fields: mergeFields() },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? t('contacts.people.merge.submitError');
                step.value = 'fields';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="description" size="lg" :persistent="submitting">
        <div v-if="step === 'candidates'" class="space-y-3" :aria-busy="loading || undefined">
            <p v-if="loading" class="py-10 text-center text-sm text-ink-subtle" role="status">
                {{ t('contacts.people.merge.loading') }}
            </p>

            <div v-else-if="error" class="rounded-control bg-danger-soft p-4 text-sm text-danger" role="alert">
                <p>{{ error }}</p>
                <Button class="mt-3" size="sm" @click="loadCandidates">{{ t('contacts.people.merge.tryAgain') }}</Button>
            </div>

            <div v-else-if="candidates.length === 0" class="py-8 text-center">
                <SearchX class="mx-auto size-8 text-ink-subtle" aria-hidden="true" />
                <h3 class="mt-3 font-medium text-ink">{{ t('contacts.people.merge.emptyTitle') }}</h3>
                <p class="mx-auto mt-1 max-w-md text-sm text-ink-subtle">{{ t('contacts.people.merge.emptyBody') }}</p>
            </div>

            <article v-for="candidate in candidates" v-else :key="candidate.id" class="rounded-card border border-line bg-surface p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="truncate font-medium text-ink">{{ candidate.name }}</h3>
                        <p class="mt-1 text-sm text-ink-muted">{{ display(candidate.email) }} · {{ display(candidate.phone) }}</p>
                        <p v-if="candidate.company || candidate.owner" class="mt-1 text-[0.8125rem] text-ink-subtle">
                            <span v-if="candidate.company">{{ candidate.company.name }}</span>
                            <span v-if="candidate.company && candidate.owner" aria-hidden="true"> · </span>
                            <span v-if="candidate.owner">{{ t('contacts.people.merge.ownedBy', { name: candidate.owner.name }) }}</span>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="text-xs text-ink-subtle">{{ t('contacts.people.merge.matchedBy') }}</span>
                            <Badge v-for="reason in candidate.matchReasons" :key="reason" tone="info">
                                {{ t(`contacts.people.merge.reasons.${reason}`) }}
                            </Badge>
                        </div>
                    </div>

                    <Button v-if="candidate.mergeable" size="sm" @click="review(candidate)">
                        <template #icon><GitMerge class="size-4" aria-hidden="true" /></template>
                        {{ t('contacts.people.merge.review') }}
                    </Button>
                    <Badge v-else tone="neutral">
                        <ShieldX class="size-3.5" aria-hidden="true" />
                        {{ t('contacts.people.merge.noPermission') }}
                    </Badge>
                </div>
            </article>
        </div>

        <div v-else-if="step === 'fields'" class="space-y-4">
            <div v-if="comparisons.length === 0" class="rounded-control bg-hover p-4 text-sm text-ink-muted">
                {{ t('contacts.people.merge.noDifferences') }}
            </div>

            <fieldset v-for="row in comparisons" v-else :key="row.key" class="rounded-card border border-line p-4">
                <legend class="px-1 text-sm font-semibold text-ink">{{ row.label }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="rounded-control bg-hover p-3">
                        <p class="eyebrow">{{ t('contacts.people.merge.currentContact') }}</p>
                        <p :class="['mt-1 break-words text-sm text-ink', row.multiline && 'whitespace-pre-wrap']">{{ row.canonical }}</p>
                    </div>
                    <div class="rounded-control bg-hover p-3">
                        <p class="eyebrow">{{ t('contacts.people.merge.duplicateContact') }}</p>
                        <p :class="['mt-1 break-words text-sm text-ink', row.multiline && 'whitespace-pre-wrap']">{{ row.duplicate }}</p>
                    </div>
                </div>
                <SegmentedControl
                    v-model="choices[row.key]"
                    class="mt-3"
                    :options="sourceOptions"
                    :label="t('contacts.people.merge.fieldChoice', { field: row.label })"
                />
            </fieldset>

            <p class="text-[0.8125rem] text-ink-subtle">{{ t('contacts.people.merge.otherFields') }}</p>
            <p v-if="error" class="text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
        </div>

        <div v-else class="space-y-4">
            <div class="flex items-start gap-3 rounded-card bg-danger-soft p-4 text-danger">
                <AlertTriangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div class="text-sm">
                    <p class="font-semibold">{{ t('contacts.people.merge.destructiveTitle', { name: selected?.name }) }}</p>
                    <p class="mt-1">{{ t('contacts.people.merge.destructiveBody', { name: selected?.name }) }}</p>
                </div>
            </div>

            <dl v-if="comparisons.length > 0" class="divide-y divide-line rounded-card border border-line">
                <div v-for="row in comparisons" :key="row.key" class="grid gap-1 px-4 py-3 sm:grid-cols-[9rem_1fr]">
                    <dt class="text-[0.8125rem] font-medium text-ink-subtle">{{ row.label }}</dt>
                    <dd :class="['break-words text-sm text-ink', row.multiline && 'whitespace-pre-wrap']">{{ chosenValue(row) }}</dd>
                </div>
            </dl>

            <p class="text-sm text-ink-muted">{{ t('contacts.people.merge.referencesMove', { name: props.contact?.name }) }}</p>
            <p v-if="error" class="text-[0.8125rem] text-danger" role="alert">{{ error }}</p>
        </div>

        <template #footer>
            <template v-if="step === 'candidates'">
                <Button variant="ghost" @click="open = false">{{ t('common.actions.close') }}</Button>
            </template>
            <template v-else-if="step === 'fields'">
                <Button variant="ghost" @click="step = 'candidates'">
                    <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                    {{ t('contacts.people.merge.back') }}
                </Button>
                <Button variant="primary" @click="step = 'confirm'">{{ t('contacts.people.merge.continue') }}</Button>
            </template>
            <template v-else>
                <Button variant="ghost" :disabled="submitting" @click="step = 'fields'">
                    <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                    {{ t('contacts.people.merge.back') }}
                </Button>
                <Button variant="danger" :loading="submitting" @click="submit">
                    <template #icon><Check class="size-4" aria-hidden="true" /></template>
                    {{ t('contacts.people.merge.confirm') }}
                </Button>
            </template>
        </template>
    </Modal>
</template>

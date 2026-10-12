<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';

/**
 * Puts an existing candidate on an opening. Candidates are found with the same
 * lookup the add-candidate form uses, so only ones the person may see appear.
 */
const props = defineProps<{ open: boolean; openingId: string }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ candidate_id: '', source: '' });
const query = ref('');
const results = ref<{ id: string; name: string; email: string | null }[]>([]);
const chosen = ref<{ id: string; name: string } | null>(null);
const searched = ref(false);

watch(() => props.open, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        query.value = '';
        results.value = [];
        chosen.value = null;
        searched.value = false;
    }
}, { immediate: true });

async function search(): Promise<void> {
    const term = query.value.trim();

    if (term === '') {
        results.value = [];

        return;
    }

    try {
        const response = await fetch(route('talent.candidates.lookup', { q: term }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        results.value = response.ok ? ((await response.json()) as { matches: { id: string; name: string; email: string | null }[] }).matches : [];
    } catch {
        results.value = [];
    }

    searched.value = true;
}

function choose(candidate: { id: string; name: string }): void {
    chosen.value = candidate;
    form.candidate_id = candidate.id;
}

function submit(): void {
    form.transform((data) => ({ candidate_id: data.candidate_id, source: data.source.trim() === '' ? null : data.source.trim() }))
        .post(route('talent.openings.applications.store', { opening: props.openingId }), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        :title="t('talent.openings.addCandidate')"
        :description="t('talent.openings.addCandidateHint')"
        :submit-label="t('talent.openings.addCandidateSubmit')"
        :processing="form.processing"
        :disabled="form.candidate_id === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="flex items-end gap-2">
            <Input v-model="query" :label="t('talent.openings.findCandidate')" class="min-w-0 flex-1" maxlength="100" @keydown.enter.prevent="search" />
            <Button type="button" variant="secondary" @click="search">{{ t('talent.common.search') }}</Button>
        </div>

        <p v-if="searched && results.length === 0" class="text-sm text-ink-muted">{{ t('talent.openings.noCandidateFound') }}</p>
        <ul v-else-if="results.length > 0" class="grid gap-1" :aria-label="t('talent.openings.candidateResults')">
            <li v-for="candidate in results" :key="candidate.id">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:outline-brand"
                    :class="chosen?.id === candidate.id ? 'bg-brand-soft text-brand-text' : 'text-ink'"
                    :aria-pressed="chosen?.id === candidate.id"
                    @click="choose(candidate)"
                >
                    <span class="min-w-0 truncate">{{ candidate.name }}</span>
                    <span class="truncate text-xs text-ink-subtle">{{ candidate.email }}</span>
                </button>
            </li>
        </ul>

        <p v-if="chosen" class="text-sm text-ink" data-testid="chosen-candidate">{{ t('talent.openings.chosenCandidate', { name: chosen.name }) }}</p>
        <p v-if="form.errors.candidate_id" class="text-xs text-danger" role="alert">{{ form.errors.candidate_id }}</p>

        <Input v-model="form.source" :label="t('talent.fields.source')" maxlength="60" optional :error="form.errors.source" />
    </TalentFormDialog>
</template>

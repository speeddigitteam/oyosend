<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { TicketReference } from './types';

/**
 * Merge this ticket into another. When the two have different requesters the
 * server answers with a `confirm` error; the confirmation checkbox then
 * appears and the merge must be confirmed explicitly.
 */
const props = defineProps<{ ticketId: string; number: string; candidates: TicketReference[] }>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const form = useForm({ target_number: '', note: '', confirm: false });

const confirmNeeded = computed(() => Boolean((form.errors as Record<string, string | undefined>).confirm));
const targetError = computed(() => form.errors.target_number ?? (form.errors as Record<string, string | undefined>).target_id);
const options = computed(() => [
    { value: '', label: t('support.merge.pick') },
    ...props.candidates.map((candidate) => ({ value: candidate.number, label: `${candidate.number} - ${candidate.subject ?? ''}` })),
]);

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.post(route('support.tickets.merge', { ticket: props.ticketId }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('support.merge.title', { number })" :description="t('support.merge.description')" :persistent="form.processing">
        <form id="support-merge-form" class="grid gap-4" @submit.prevent="submit">
            <Select
                v-if="candidates.length"
                v-model="form.target_number"
                :options="options"
                :label="t('support.merge.related')"
                :hint="t('support.merge.relatedHint')"
            />
            <Input v-model="form.target_number" :label="t('support.merge.targetNumber')" :error="targetError" required maxlength="40" mono />
            <Textarea v-model="form.note" :label="t('support.merge.note')" :hint="t('support.merge.noteHint')" :error="form.errors.note" optional maxlength="500" />
            <div v-if="confirmNeeded" class="rounded-control border border-warn bg-warn-soft px-3 py-3" role="alert">
                <p class="text-sm text-ink">{{ (form.errors as Record<string, string | undefined>).confirm }}</p>
                <Checkbox v-model="form.confirm" class="mt-2" :label="t('support.merge.confirm')" />
            </div>
            <p class="text-sm text-ink-muted">{{ t('support.merge.irreversible') }}</p>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-merge-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.merge.submit') }}</Button>
        </template>
    </Modal>
</template>

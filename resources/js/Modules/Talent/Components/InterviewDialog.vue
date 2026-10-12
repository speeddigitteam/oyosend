<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import TalentFormDialog from './TalentFormDialog.vue';
import { parseInteger } from './talentFormat';
import type { InterviewMode, InterviewRow, PersonOption } from './types';

/**
 * Schedules or reschedules an interview. The date and time are local to the
 * workspace time zone (shown in the hint); the server stores them as UTC with
 * the zone name. The panel is one to ten working employees.
 */
const props = defineProps<{
    open: boolean;
    /** Required to schedule a new interview; unused when `interview` is given. */
    applicationId?: string;
    interview: InterviewRow | null;
    employees: PersonOption[];
    timezone: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const MODES: InterviewMode[] = ['in_person', 'video', 'phone'];
const MAX_PANEL = 10;

const form = useForm({
    round_name: '',
    mode: 'in_person' as InterviewMode,
    date: '',
    time: '',
    duration_minutes: '60',
    location: '',
    notes: '',
    panel: [] as string[],
});

const filter = ref('');
const modeOptions = computed(() => MODES.map((value) => ({ value, label: t(`talent.status.mode.${value}`) })));
const duration = computed(() => parseInteger(form.duration_minutes));
const durationInvalid = computed(() => duration.value === null || duration.value < 15 || duration.value > 480);
const visible = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return term === '' ? props.employees : props.employees.filter((person) => `${person.name} ${person.number}`.toLowerCase().includes(term));
});

watch(() => props.open, (open) => {
    if (!open) {
        return;
    }

    const interview = props.interview;

    form.defaults({
        round_name: interview?.roundName ?? '',
        mode: interview?.mode ?? 'in_person',
        date: interview?.date ?? '',
        time: interview?.time ?? '',
        duration_minutes: String(interview?.durationMinutes ?? 60),
        location: interview?.location ?? '',
        notes: interview?.notes ?? '',
        panel: interview?.panel.map((person) => person.id) ?? [],
    });
    form.reset();
    form.clearErrors();
    filter.value = '';
}, { immediate: true });

function toggle(id: string, on: boolean): void {
    if (on) {
        if (!form.panel.includes(id) && form.panel.length < MAX_PANEL) {
            form.panel = [...form.panel, id];
        }
    } else {
        form.panel = form.panel.filter((value) => value !== id);
    }
}

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form.transform((data) => ({
        round_name: data.round_name.trim(),
        mode: data.mode,
        date: data.date,
        time: data.time,
        duration_minutes: parseInteger(data.duration_minutes) ?? 60,
        location: blank(data.location),
        notes: blank(data.notes),
        panel: data.panel,
    }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.interview) {
        form.put(route('talent.interviews.update', { interview: props.interview.id }), options);
    } else {
        form.post(route('talent.interviews.store', { application: props.applicationId ?? '' }), options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="lg"
        :title="interview ? t('talent.interviews.reschedule') : t('talent.interviews.schedule')"
        :description="t('talent.interviews.timezoneHint', { timezone })"
        :submit-label="interview ? t('talent.common.save') : t('talent.interviews.schedule')"
        :processing="form.processing"
        :disabled="form.round_name.trim() === '' || form.date === '' || form.time === '' || durationInvalid || form.panel.length === 0"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <Input v-model="form.round_name" :label="t('talent.interviews.round')" maxlength="120" :error="form.errors.round_name" required autofocus />
            <Select v-model="form.mode" :options="modeOptions" :label="t('talent.fields.mode')" :error="form.errors.mode" />
            <Input v-model="form.date" type="date" :label="t('talent.fields.date')" :error="form.errors.date" required />
            <Input v-model="form.time" type="time" :label="t('talent.fields.time')" :error="form.errors.time" required />
            <Input
                v-model="form.duration_minutes"
                type="number"
                min="15"
                max="480"
                step="5"
                :label="t('talent.interviews.duration')"
                :hint="t('talent.interviews.durationHint')"
                :error="form.errors.duration_minutes"
                required
            />
            <Input v-model="form.location" :label="t('talent.fields.location')" maxlength="255" optional :error="form.errors.location" />
        </div>

        <fieldset class="grid gap-2">
            <legend class="eyebrow mb-1">{{ t('talent.interviews.panel') }}</legend>
            <p class="text-xs text-ink-subtle">{{ t('talent.interviews.panelHint', { max: MAX_PANEL }) }}</p>
            <Input v-model="filter" type="search" :label="t('talent.interviews.filterPanel')" />
            <ul class="grid max-h-48 gap-0.5 overflow-y-auto rounded-control border border-line p-1" data-testid="panel-options">
                <li v-for="person in visible" :key="person.id">
                    <Checkbox
                        :model-value="form.panel.includes(person.id)"
                        :label="person.name"
                        :description="person.department ?? undefined"
                        :disabled="!form.panel.includes(person.id) && form.panel.length >= MAX_PANEL"
                        @update:model-value="toggle(person.id, $event)"
                    />
                </li>
                <li v-if="visible.length === 0" class="px-2 py-2 text-sm text-ink-muted">{{ t('talent.common.noMatches') }}</li>
            </ul>
            <p v-if="form.errors.panel" class="text-xs text-danger" role="alert">{{ form.errors.panel }}</p>
        </fieldset>

        <Textarea v-model="form.notes" :label="t('talent.fields.note')" maxlength="2000" optional :error="form.errors.notes" />
    </TalentFormDialog>
</template>

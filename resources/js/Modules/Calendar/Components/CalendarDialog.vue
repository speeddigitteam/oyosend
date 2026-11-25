<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Field from '@/Core/Components/Field.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import { colorVar } from '../lib/format';
import type { CalendarInfo, EventColor } from '../types';

/** Create a personal or workspace calendar, or change or delete one. */
const props = defineProps<{
    calendar: CalendarInfo | null;
    /** For a new calendar: a workspace calendar everyone sees, or a personal one. */
    shared: boolean;
    colors: EventColor[];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const form = useForm({
    name: '',
    description: '',
    color: 'blue' as EventColor,
    visibility: 'busy' as CalendarInfo['visibility'],
    shared: false,
});

const isShared = computed(() => props.calendar?.shared ?? props.shared);
const confirmingDelete = ref(false);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    confirmingDelete.value = false;
    form.name = props.calendar?.name ?? '';
    form.description = props.calendar?.description ?? '';
    form.color = props.calendar?.color ?? (props.shared ? 'teal' : 'green');
    form.visibility = props.calendar?.visibility ?? 'busy';
    form.shared = props.shared;
});

const visibilityOptions = computed(() =>
    (['private', 'busy', 'team'] as const).map((value) => ({ value, label: t(`calendar.calendarForm.visibilities.${value}`) })),
);

function save(): void {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { open.value = false; } };

    if (props.calendar) {
        form.put(route('calendar.calendars.update', { calendar: props.calendar.id }), options);
    } else {
        form.post(route('calendar.calendars.store'), options);
    }
}

function destroy(): void {
    if (!props.calendar) {
        return;
    }

    router.delete(route('calendar.calendars.destroy', { calendar: props.calendar.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { open.value = false; },
    });
}

const title = computed(() => {
    if (props.calendar) {
        return t('calendar.calendarForm.editHeading');
    }

    return props.shared ? t('calendar.calendarForm.createSharedHeading') : t('calendar.calendarForm.createHeading');
});
</script>

<template>
    <Modal v-model:open="open" :title="title" :description="isShared ? t('calendar.calendarForm.sharedNote') : undefined" :persistent="form.processing">
        <div v-if="confirmingDelete" class="grid gap-2">
            <p class="text-sm text-ink">{{ t('calendar.calendarForm.deleteBody', { name: calendar?.name ?? '' }) }}</p>
        </div>
        <form v-else id="calendar-calendar-form" class="grid gap-4" @submit.prevent="save">
            <Input v-model="form.name" :label="t('calendar.calendarForm.name')" :error="form.errors.name" maxlength="100" required autofocus />
            <Textarea v-model="form.description" :label="t('calendar.calendarForm.description')" :error="form.errors.description" :rows="2" maxlength="500" optional />
            <Field :label="t('calendar.calendarForm.color')" :error="form.errors.color">
                <div role="radiogroup" :aria-label="t('calendar.calendarForm.color')" class="flex flex-wrap gap-1.5">
                    <button
                        v-for="color in colors"
                        :key="color"
                        type="button"
                        role="radio"
                        :aria-checked="form.color === color"
                        :aria-label="t(`calendar.colors.${color}`)"
                        :title="t(`calendar.colors.${color}`)"
                        :style="colorVar(color)"
                        :class="['size-7 rounded-full bg-(--ev) transition focus-visible:ring-2 focus-visible:ring-ring', form.color === color ? 'ring-2 ring-ink ring-offset-2 ring-offset-(--canvas)' : 'hover:scale-110']"
                        @click="form.color = color"
                    />
                </div>
            </Field>
            <Select
                v-if="!isShared"
                v-model="form.visibility"
                :options="visibilityOptions"
                :label="t('calendar.calendarForm.visibility')"
                :hint="t('calendar.calendarForm.visibilityHint')"
                :error="form.errors.visibility"
            />
            <p v-if="calendar?.isPrimary" class="text-xs text-ink-subtle">{{ t('calendar.calendarForm.primaryNote') }}</p>
        </form>

        <template #footer>
            <template v-if="confirmingDelete">
                <Button variant="ghost" @click="confirmingDelete = false">{{ t('calendar.common.cancel') }}</Button>
                <Button variant="danger" @click="destroy">{{ t('calendar.calendarForm.delete') }}</Button>
            </template>
            <template v-else>
                <Button v-if="calendar && !calendar.isPrimary" variant="ghost" class="mr-auto text-danger" @click="confirmingDelete = true">{{ t('calendar.calendarForm.delete') }}</Button>
                <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('calendar.common.cancel') }}</Button>
                <Button type="submit" form="calendar-calendar-form" variant="primary" :loading="form.processing">{{ t('calendar.common.save') }}</Button>
            </template>
        </template>
    </Modal>
</template>

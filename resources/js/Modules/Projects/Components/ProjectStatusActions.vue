<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import { route } from '@/Core/lib/route';
import type { ProjectStatus } from './types';

interface Transition {
    status: ProjectStatus;
    label: string;
    terminal?: boolean;
}

const props = defineProps<{ projectId: string; status: ProjectStatus }>();
const { t } = useI18n();
const pending = ref<Transition | null>(null);
const saving = ref(false);
const error = ref('');
const transitions = computed<Transition[]>(() => {
    const actions: Partial<Record<ProjectStatus, Transition[]>> = {
        planned: [
            { status: 'active', label: t('projects.transitions.start') },
            { status: 'cancelled', label: t('projects.transitions.cancel'), terminal: true },
        ],
        active: [
            { status: 'on_hold', label: t('projects.transitions.hold') },
            { status: 'completed', label: t('projects.transitions.complete'), terminal: true },
            { status: 'cancelled', label: t('projects.transitions.cancel'), terminal: true },
        ],
        on_hold: [
            { status: 'active', label: t('projects.transitions.resume') },
            { status: 'cancelled', label: t('projects.transitions.cancel'), terminal: true },
        ],
    };
    return actions[props.status] ?? [];
});

function choose(transition: Transition): void {
    error.value = '';
    if (transition.terminal) pending.value = transition;
    else update(transition.status);
}

function update(status: ProjectStatus): void {
    saving.value = true;
    router.patch(route('projects.status', { project: props.projectId }), { status }, {
        preserveScroll: true,
        onError: (errors) => { error.value = errors.status ?? errors.default ?? Object.values(errors)[0] ?? ''; },
        onSuccess: () => { error.value = ''; pending.value = null; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div v-if="transitions.length" class="flex flex-wrap gap-1">
        <Button v-for="transition in transitions" :key="transition.status" size="sm" :variant="transition.terminal ? 'ghost' : 'secondary'" :disabled="saving" @click="choose(transition)">{{ transition.label }}</Button>
    </div>
    <p v-if="error && pending === null" class="basis-full text-sm text-danger" role="alert">{{ error }}</p>
    <ConfirmDialog :open="pending !== null" :title="pending?.label ?? t('projects.transitions.change')" :description="pending ? t('projects.transitions.confirm', { action: pending.label }) : undefined" :confirm-label="pending?.label" :loading="saving" @update:open="!$event && !saving && (pending = null)" @confirm="pending && update(pending.status)"><p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p></ConfirmDialog>
</template>

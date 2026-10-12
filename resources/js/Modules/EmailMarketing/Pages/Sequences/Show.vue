<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmailBadge from '../../Components/EmailBadge.vue';
import { delayParts } from '../../Components/emailFormat';
import type { SequenceRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ sequence: SequenceRow; canManage: boolean; canActivate: boolean }>();

const { t } = useI18n();
const format = useFormat();

const busy = ref(false);
const deleting = ref(false);

const tiles = computed(() => [
    { key: 'active', value: props.sequence.enrollments.active },
    { key: 'completed', value: props.sequence.enrollments.completed },
    { key: 'stopped', value: props.sequence.enrollments.stopped },
]);

function act(name: 'activate' | 'pause'): void {
    busy.value = true;
    router.post(route(`email_marketing.sequences.${name}`, { sequence: props.sequence.id }), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
}

function destroy(): void {
    busy.value = true;
    router.delete(route('email_marketing.sequences.destroy', { sequence: props.sequence.id }), {
        onFinish: () => {
            busy.value = false;
            deleting.value = false;
        },
    });
}

function delayText(minutes: number): string {
    const parts = delayParts(minutes);

    return minutes === 0 ? t('emailMarketing.sequences.immediately') : t(`emailMarketing.sequences.after.${parts.unit}`, { count: format.number(parts.value) });
}
</script>

<template>
    <PageHeader :eyebrow="t('emailMarketing.sequences.title')" :title="sequence.name" :description="sequence.description ?? undefined">
        <template #actions>
            <EmailBadge kind="sequence" :value="sequence.status" />
            <Button v-if="canActivate && sequence.status !== 'active'" variant="primary" :loading="busy" @click="act('activate')">{{ t('emailMarketing.sequences.activate') }}</Button>
            <Button v-if="canActivate && sequence.status === 'active'" :loading="busy" @click="act('pause')">{{ t('emailMarketing.sequences.pause') }}</Button>
            <Button v-if="canManage && sequence.status !== 'active'" :href="route('email_marketing.sequences.edit', { sequence: sequence.id })">{{ t('emailMarketing.common.edit') }}</Button>
            <Button v-if="canManage" variant="ghost" @click="deleting = true">{{ t('emailMarketing.common.delete') }}</Button>
        </template>
    </PageHeader>

    <p v-if="sequence.status === 'active'" class="mb-4 text-[0.8125rem] text-ink-muted" role="status">{{ t('emailMarketing.sequences.activeNote') }}</p>

    <dl class="mb-3 grid grid-cols-3 gap-3" data-testid="enrollments">
        <div v-for="tile in tiles" :key="tile.key" class="glass rounded-card px-4 py-3">
            <dt class="eyebrow">{{ t(`emailMarketing.sequences.enrollment.${tile.key}`) }}</dt>
            <dd class="figure mt-2 text-2xl text-ink">{{ format.number(tile.value) }}</dd>
        </div>
    </dl>

    <Card :title="t('emailMarketing.sequences.steps')" :description="t('emailMarketing.sequences.trigger') + ': ' + (sequence.listName ?? '')">
        <ol class="grid gap-3">
            <li v-for="step in sequence.steps" :key="step.id ?? step.position" class="glass-well rounded-card p-4">
                <p class="eyebrow">{{ t('emailMarketing.sequences.stepTitle', { number: step.position }) }} - {{ delayText(step.delayMinutes) }}</p>
                <p class="mt-1 text-sm font-medium text-ink">{{ step.subject }}</p>
                <p v-if="step.preheader" class="text-[0.8125rem] text-ink-muted">{{ step.preheader }}</p>
                <p class="mt-1 text-xs text-ink-subtle">{{ t('emailMarketing.sequences.blocks', { count: format.number(step.blocks.length) }) }}</p>
            </li>
        </ol>
    </Card>

    <ConfirmDialog
        :open="deleting"
        :title="t('emailMarketing.sequences.deleteTitle')"
        :description="t('emailMarketing.sequences.deleteBody')"
        :confirm-label="t('emailMarketing.common.delete')"
        :loading="busy"
        @update:open="deleting = $event"
        @confirm="destroy"
    />
</template>

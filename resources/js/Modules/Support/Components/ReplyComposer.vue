<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import AttachmentPicker from './AttachmentPicker.vue';
import { isFinished } from './supportFormat';
import type { MessageKind, Option, TicketAbilities, TicketStatus } from './types';

/**
 * Reply or note composer. The status choices are the server's `statusTargets`,
 * narrowed to what the person may do; the server checks again.
 */
const props = defineProps<{
    ticketId: string;
    status: TicketStatus;
    abilities: TicketAbilities;
    statusTargets: TicketStatus[];
    canned: Option[];
    maxAttachments: number;
}>();

const { t } = useI18n();

const form = useForm<{ kind: MessageKind; body: string; status: string; attachments: File[] }>({
    kind: props.abilities.publicReply ? 'public_reply' : 'internal_note',
    body: '',
    status: '',
    attachments: [],
});

const cannedId = ref('');
const preview = ref<string | null>(null);
const previewError = ref('');
const previewing = ref(false);

const kindOptions = computed(() => {
    const options: { value: MessageKind; label: string }[] = [];

    if (props.abilities.publicReply) {
        options.push({ value: 'public_reply', label: t('support.composer.reply') });
    }

    options.push({ value: 'internal_note', label: t('support.composer.note') });

    if (props.abilities.publicReply) {
        options.push({ value: 'customer_reply', label: t('support.composer.logCustomer') });
    }

    return options;
});

function allowed(target: TicketStatus): boolean {
    if (target === 'resolved' || target === 'closed') {
        return props.abilities.resolve;
    }

    return isFinished(props.status) ? props.abilities.reopen : true;
}

const statusOptions = computed(() => [
    { value: '', label: t('support.composer.keepStatus') },
    ...props.statusTargets.filter((target) => target !== props.status && allowed(target)).map((value) => ({ value, label: t(`support.status.${value}`) })),
]);
const cannedOptions = computed(() => [{ value: '', label: t('support.composer.chooseCanned') }, ...props.canned.map((item) => ({ value: item.id, label: item.name }))]);

const showCanned = computed(() => props.abilities.useCanned && props.canned.length > 0 && form.kind !== 'internal_note');

const attachmentError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.attachments ?? Object.entries(errors).find(([key]) => key.startsWith('attachments.'))?.[1];
});

async function loadPreview(): Promise<void> {
    preview.value = null;
    previewError.value = '';

    if (cannedId.value === '') {
        return;
    }

    previewing.value = true;

    try {
        const response = await fetch(route('support.tickets.canned.preview', { ticket: props.ticketId, canned: cannedId.value }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const payload = (await response.json()) as { body: string };
        preview.value = payload.body;
    } catch {
        previewError.value = t('support.composer.previewFailed');
    } finally {
        previewing.value = false;
    }
}

watch(cannedId, () => void loadPreview());

function insertPreview(): void {
    if (preview.value === null) {
        return;
    }

    form.body = form.body.trim() === '' ? preview.value : `${form.body.replace(/\s+$/, '')}\n\n${preview.value}`;
    preview.value = null;
    cannedId.value = '';
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        status: data.status === '' ? null : data.status,
        attachments: data.attachments.length ? data.attachments : undefined,
    })).post(route('support.tickets.messages.store', { ticket: props.ticketId }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset('body', 'status', 'attachments');
            form.clearErrors();
            preview.value = null;
            cannedId.value = '';
        },
    });
}
</script>

<template>
    <Card :title="t('support.composer.title')">
        <form class="grid gap-4" @submit.prevent="submit">
            <SegmentedControl v-model="form.kind" :options="kindOptions" :label="t('support.composer.kind')" />
            <p v-if="form.kind === 'internal_note'" class="text-sm text-ink-muted">{{ t('support.composer.noteHint') }}</p>
            <p v-else-if="form.kind === 'customer_reply'" class="text-sm text-ink-muted">{{ t('support.composer.logHint') }}</p>
            <p v-else class="text-sm text-ink-muted">{{ t('support.composer.replyHint') }}</p>

            <div v-if="showCanned" class="grid gap-2">
                <div class="flex flex-wrap items-end gap-2">
                    <Select v-model="cannedId" class="min-w-56 flex-1" :options="cannedOptions" :label="t('support.composer.canned')" />
                </div>
                <div v-if="previewing" class="text-sm text-ink-muted" role="status">{{ t('support.composer.loadingPreview') }}</div>
                <p v-if="previewError" class="text-sm text-danger" role="alert">{{ previewError }}</p>
                <div v-if="preview !== null" class="glass-well grid gap-2 rounded-control p-3">
                    <p class="eyebrow">{{ t('support.composer.preview') }}</p>
                    <p class="text-sm whitespace-pre-wrap wrap-anywhere text-ink">{{ preview }}</p>
                    <div class="flex gap-2">
                        <Button size="sm" variant="primary" @click="insertPreview">{{ t('support.composer.insert') }}</Button>
                        <Button size="sm" variant="ghost" @click="preview = null">{{ t('support.common.dismiss') }}</Button>
                    </div>
                </div>
            </div>

            <Textarea v-model="form.body" :rows="6" :label="t('support.composer.message')" :error="form.errors.body" required maxlength="20000" />
            <AttachmentPicker v-model="form.attachments" :max="maxAttachments" :error="attachmentError" />

            <div class="flex flex-wrap items-end justify-between gap-3">
                <Select
                    v-if="abilities.reply && statusOptions.length > 1"
                    v-model="form.status"
                    class="min-w-56"
                    :options="statusOptions"
                    :label="t('support.composer.setStatus')"
                    :error="form.errors.status"
                />
                <span v-else />
                <Button type="submit" variant="primary" :loading="form.processing">
                    {{ t(form.kind === 'internal_note' ? 'support.composer.addNote' : form.kind === 'customer_reply' ? 'support.composer.logMessage' : 'support.composer.send') }}
                </Button>
            </div>
        </form>
    </Card>
</template>

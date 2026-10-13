<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { StickyNote, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { ConversationNoteRow } from './types';

/**
 * Internal notes on a conversation: for the team only, never sent to the customer. The body is team-written
 * text, rendered as text. Removing is offered where the server said the viewer may (`removable`).
 */
const props = defineProps<{
    conversationId: string;
    notes: ConversationNoteRow[];
    canAdd: boolean;
}>();

const MAX_LENGTH = 2000;

const { t } = useI18n();
const format = useFormat();

const form = useForm({ body: '' });
const removing = ref<string | null>(null);

function add(): void {
    form.post(route('whatsapp_marketing.inbox.notes.store', { conversation: props.conversationId }), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(note: ConversationNoteRow): void {
    removing.value = note.id;
    router.delete(route('whatsapp_marketing.inbox.notes.destroy', { conversation: props.conversationId, note: note.id }), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}
</script>

<template>
    <Card :title="t('whatsapp.notes.title')" :description="t('whatsapp.notes.description')">
        <p v-if="notes.length === 0" class="text-sm text-ink-subtle" data-testid="notes-empty">{{ t('whatsapp.notes.empty') }}</p>
        <ul v-else class="grid gap-2" :aria-label="t('whatsapp.notes.title')">
            <li v-for="note in notes" :key="note.id" class="grid gap-1 rounded-control border border-warn/30 bg-warn-soft px-3 py-2" data-testid="note">
                <div class="flex items-start gap-2">
                    <StickyNote class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    <p class="min-w-0 flex-1 text-sm whitespace-pre-wrap text-ink wrap-anywhere">{{ note.body }}</p>
                    <Button v-if="note.removable" size="sm" variant="ghost" icon :loading="removing === note.id" @click="remove(note)">
                        <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.notes.remove') }}
                    </Button>
                </div>
                <p class="text-xs text-ink-subtle">
                    {{ t('whatsapp.notes.byline', { name: note.author ?? t('whatsapp.notes.formerMember'), when: format.date(note.createdAt, 'datetime') }) }}
                </p>
            </li>
        </ul>

        <form v-if="canAdd" class="mt-3 grid gap-2" data-testid="note-form" @submit.prevent="add">
            <Textarea v-model="form.body" :label="t('whatsapp.notes.label')" :error="form.errors.body" :rows="2" :maxlength="MAX_LENGTH" />
            <div>
                <Button type="submit" size="sm" variant="secondary" :loading="form.processing" :disabled="form.body.trim() === ''">
                    {{ t('whatsapp.notes.add') }}
                </Button>
            </div>
        </form>
        <p v-else class="mt-3 text-xs text-ink-subtle">{{ t('whatsapp.notes.readOnly') }}</p>
    </Card>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, EyeOff, ExternalLink, Eye, Heart, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { CommentCan, CommentThreadData, Member } from './commentTypes';

/**
 * The conversation of one comment and everything that can be done to it. Every piece of text is interpolated
 * (escaped) and never rendered as HTML. Controls the person may not use are not shown; the server decides anyway.
 */
const props = defineProps<{
    thread: CommentThreadData;
    can: CommentCan;
    members: Member[];
    limits: { reply: number; note: number; tag: number };
}>();

defineEmits<{ back: [] }>();

const { t } = useI18n();
const format = useFormat();

const focus = computed(() => props.thread.comments.find((item) => item.id === props.thread.focus) ?? props.thread.comments[0]);
const gone = computed(() => focus.value.deleted);
const canAct = computed(() => !gone.value && !focus.value.isFromPage);

const reply = useForm({ message: '' });
const note = useForm({ body: '' });
const tag = useForm({ tag: '' });
const confirmDelete = ref(false);
const busy = ref(false);

const assignOptions = computed(() => [
    { value: '', label: t('socialComments.thread.unassigned') },
    ...props.members.map((member) => ({ value: member.id, label: member.name })),
]);
const assignee = computed({
    get: () => focus.value.assignee?.id ?? '',
    set: (value: string | number | null | undefined) => {
        router.put(route('social.comments.assign', { comment: focus.value.id }), { membership_id: value === '' || value == null ? null : String(value) }, { preserveScroll: true });
    },
});

function submitReply(): void {
    reply.post(route('social.comments.reply', { comment: focus.value.id }), {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

function submitNote(): void {
    note.post(route('social.comments.notes.store', { comment: focus.value.id }), { preserveScroll: true, onSuccess: () => note.reset() });
}

function submitTag(): void {
    tag.post(route('social.comments.tags.store', { comment: focus.value.id }), { preserveScroll: true, onSuccess: () => tag.reset() });
}

function removeTag(value: string): void {
    router.delete(route('social.comments.tags.destroy', { comment: focus.value.id }), { data: { tag: value }, preserveScroll: true });
}

function put(name: string, data: Record<string, unknown>): void {
    busy.value = true;
    router.put(route(name, { comment: focus.value.id }), data as never, { preserveScroll: true, onFinish: () => (busy.value = false) });
}

function remove(): void {
    router.delete(route('social.comments.destroy', { comment: focus.value.id }), {
        preserveScroll: true,
        onFinish: () => (confirmDelete.value = false),
    });
}
</script>

<template>
    <Card flush class="min-w-0" data-testid="comment-thread">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
            <Button size="sm" variant="ghost" class="lg:hidden" @click="$emit('back')">
                <template #icon><ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" /></template>
                {{ t('socialComments.thread.back') }}
            </Button>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ focus.accountName }} <span class="text-ink-subtle">({{ focus.platformLabel }})</span></p>
                <p v-if="focus.post" class="truncate text-xs text-ink-subtle">{{ t('socialComments.thread.onPost', { title: focus.post.title }) }}</p>
            </div>
            <a
                v-if="focus.permalink"
                :href="focus.permalink"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-xs text-brand-text hover:underline"
            >
                {{ t('socialComments.thread.openOnPlatform') }}
                <ExternalLink class="size-3" aria-hidden="true" />
            </a>
        </div>

        <ul class="grid gap-3 px-4 py-4" :aria-label="t('socialComments.thread.conversation')">
            <li
                v-for="item in thread.comments"
                :key="item.id"
                class="max-w-[92%] rounded-card border border-line px-3 py-2"
                :class="[
                    item.isFromPage ? 'ms-auto bg-brand-soft' : 'bg-control',
                    item.id === focus.id ? 'ring-1 ring-brand' : '',
                    item.isReply ? 'ms-6' : '',
                ]"
            >
                <p class="flex flex-wrap items-baseline gap-x-2 text-xs text-ink-subtle">
                    <span class="font-medium text-ink">{{ item.isFromPage ? item.accountName : (item.authorName ?? t('socialComments.list.unknownAuthor')) }}</span>
                    <span class="tabular">{{ format.date(item.commentedAt, 'datetime') }}</span>
                    <Badge v-if="item.hidden" tone="neutral">{{ t('socialComments.status.hidden') }}</Badge>
                    <Badge v-if="item.deleted" tone="danger">{{ t('socialComments.status.deleted') }}</Badge>
                </p>
                <p class="mt-1 break-words whitespace-pre-line text-sm text-ink">{{ item.message ?? t('socialComments.list.noText') }}</p>
                <p v-if="item.hasAttachment" class="mt-1 text-xs text-ink-subtle">{{ t('socialComments.thread.hasAttachment') }}</p>
                <p v-if="item.likeCount > 0" class="mt-1 inline-flex items-center gap-1 text-xs text-ink-subtle">
                    <Heart class="size-3" aria-hidden="true" />{{ format.number(item.likeCount) }}
                </p>
            </li>
        </ul>

        <p v-if="focus.lastError" role="alert" class="mx-4 mb-3 rounded-control bg-warn-soft px-3 py-2 text-[0.8125rem] text-warn">
            {{ t('socialComments.thread.lastError', { code: focus.lastError }) }}
        </p>

        <div v-if="canAct" class="grid gap-4 border-t border-line px-4 py-4">
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.reply" size="sm" :disabled="busy" @click="put('social.comments.like', { liked: !focus.likedByPage })">
                    <template #icon><Heart class="size-4" aria-hidden="true" /></template>
                    {{ focus.likedByPage ? t('socialComments.actions.unlike') : t('socialComments.actions.like') }}
                </Button>
                <Button v-if="can.moderate" size="sm" :disabled="busy" @click="put('social.comments.hidden', { hidden: !focus.hidden })">
                    <template #icon><component :is="focus.hidden ? Eye : EyeOff" class="size-4" aria-hidden="true" /></template>
                    {{ focus.hidden ? t('socialComments.actions.unhide') : t('socialComments.actions.hide') }}
                </Button>
                <Button v-if="can.reply" size="sm" :disabled="busy" @click="put('social.comments.handled', { handled: !focus.handled })">
                    <template #icon><component :is="focus.handled ? RotateCcw : Check" class="size-4" aria-hidden="true" /></template>
                    {{ focus.handled ? t('socialComments.actions.reopen') : t('socialComments.actions.markHandled') }}
                </Button>
                <Button v-if="can.moderate" size="sm" variant="danger" :disabled="busy" @click="confirmDelete = true">
                    <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                    {{ t('socialComments.actions.delete') }}
                </Button>
            </div>

            <form v-if="can.reply" class="grid gap-2" @submit.prevent="submitReply">
                <Textarea
                    v-model="reply.message"
                    :label="t('socialComments.thread.replyLabel')"
                    :error="reply.errors.message"
                    :rows="3"
                    :maxlength="limits.reply"
                    :hint="t('socialComments.thread.replyHint', { count: format.number(reply.message.length), max: format.number(limits.reply) })"
                />
                <div>
                    <Button type="submit" variant="primary" size="sm" :loading="reply.processing" :disabled="reply.message.trim() === ''">
                        {{ t('socialComments.actions.reply') }}
                    </Button>
                </div>
            </form>

            <div v-if="can.moderate" class="grid gap-3 sm:grid-cols-2">
                <Select v-model="assignee" :options="assignOptions" :label="t('socialComments.thread.assignTo')" />
                <form class="flex items-end gap-2" @submit.prevent="submitTag">
                    <Input v-model="tag.tag" :label="t('socialComments.thread.addTag')" :maxlength="limits.tag" :error="tag.errors.tag" class="min-w-0 flex-1" />
                    <Button type="submit" size="sm" :disabled="tag.tag.trim() === ''">{{ t('socialComments.actions.add') }}</Button>
                </form>
            </div>
            <ul v-if="focus.tags.length > 0 && can.moderate" class="flex flex-wrap gap-1.5" :aria-label="t('socialComments.thread.tags')">
                <li v-for="item in focus.tags" :key="item">
                    <button type="button" class="inline-flex items-center gap-1 rounded-full bg-info-soft px-2 py-0.5 text-xs text-info hover:brightness-95" :aria-label="t('socialComments.thread.removeTag', { tag: item })" @click="removeTag(item)">
                        {{ item }} <span aria-hidden="true">x</span>
                    </button>
                </li>
            </ul>
        </div>
        <p v-else-if="gone" class="border-t border-line px-4 py-4 text-sm text-ink-muted">{{ t('socialComments.thread.gone') }}</p>

        <section class="border-t border-line px-4 py-4" :aria-label="t('socialComments.thread.notes')">
            <h3 class="eyebrow">{{ t('socialComments.thread.notes') }}</h3>
            <p class="mt-1 text-xs text-ink-subtle">{{ t('socialComments.thread.notesHint') }}</p>
            <ul v-if="thread.notes.length > 0" class="mt-3 grid gap-2">
                <li v-for="item in thread.notes" :key="item.id" class="rounded-control bg-hover px-3 py-2">
                    <p class="text-xs text-ink-subtle">{{ item.author?.name ?? t('socialComments.thread.someone') }} · <span class="tabular">{{ format.date(item.at, 'datetime') }}</span></p>
                    <p class="mt-0.5 break-words whitespace-pre-line text-sm text-ink">{{ item.body }}</p>
                </li>
            </ul>
            <form v-if="can.reply" class="mt-3 grid gap-2" @submit.prevent="submitNote">
                <Textarea v-model="note.body" :label="t('socialComments.thread.noteLabel')" :error="note.errors.body" :rows="2" :maxlength="limits.note" />
                <div><Button type="submit" size="sm" :loading="note.processing" :disabled="note.body.trim() === ''">{{ t('socialComments.actions.addNote') }}</Button></div>
            </form>
        </section>
    </Card>

    <ConfirmDialog
        v-model:open="confirmDelete"
        :title="t('socialComments.delete.title')"
        :description="t('socialComments.delete.body')"
        :confirm-label="t('socialComments.actions.delete')"
        @confirm="remove"
    />
</template>

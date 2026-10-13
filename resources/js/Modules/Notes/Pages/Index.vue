<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Pin, Plus, Search, StickyNote, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';

type NoteColor = 'brand' | 'accent' | 'ok' | 'warn' | 'danger' | 'info' | null;

interface Option {
    id: string;
    name: string;
}

interface NoteRow {
    id: string;
    title: string | null;
    body: string | null;
    isPinned: boolean;
    color: NoteColor;
    contact: Option | null;
    company: Option | null;
    owner: Option | null;
    editable: boolean;
    createdAt: string;
}

const props = defineProps<{
    notes: Paginated<NoteRow>;
    filters: { search: string; pinned: boolean | null; contact: string | null };
    contacts: Option[];
    companies: Option[];
    owners: Option[];
    canCreate: boolean;
    prefillContact: Option | null;
}>();

defineOptions({ layout: TenantLayout });

const { t } = useI18n();
const format = useFormat();

/** Each note colour is a contrast-safe design token; the class literals stay in source so the build keeps them. */
const COLOR_CLASS: Record<Exclude<NoteColor, null>, string> = {
    brand: 'bg-brand-soft', accent: 'bg-accent-soft', ok: 'bg-ok-soft', warn: 'bg-warn-soft', danger: 'bg-danger-soft', info: 'bg-info-soft',
};
const SWATCHES: NoteColor[] = [null, 'brand', 'accent', 'ok', 'warn', 'danger', 'info'];

function cardTint(color: NoteColor): string {
    return color && COLOR_CLASS[color] ? COLOR_CLASS[color] : 'bg-glass';
}

function swatchTint(color: NoteColor): string {
    return color && COLOR_CLASS[color] ? COLOR_CLASS[color] : 'bg-canvas';
}

function colorLabel(color: NoteColor): string {
    return t(`notes.colors.${color ?? 'default'}`);
}

const pinned = computed(() => props.notes.data.filter((note) => note.isPinned));
const others = computed(() => props.notes.data.filter((note) => !note.isPinned));
const empty = computed(() => props.notes.total === 0);
const filtered = computed(() => Boolean(props.filters.search || props.filters.contact));
const count = computed(() => t(`notes.count.${props.notes.total === 1 ? 'one' : 'other'}`, { count: format.number(props.notes.total) }));

const search = ref(props.filters.search);
function applySearch(): void {
    router.get(route('notes.index'), { search: search.value || undefined, contact: props.filters.contact || undefined }, { preserveState: true, replace: true });
}
function clearSearch(): void {
    search.value = '';
    router.get(route('notes.index'), {}, { preserveState: true, replace: true });
}

// Inline "take a note" composer.
const composing = ref(false);
const bodyField = ref<HTMLTextAreaElement | null>(null);
const draft = useForm({ title: '', body: '', color: null as NoteColor, is_pinned: false, contact_id: (props.prefillContact?.id ?? '') as string });

async function openComposer(): Promise<void> {
    if (!props.canCreate) {
        return;
    }
    composing.value = true;
    await nextTick();
    bodyField.value?.focus();
}

function closeComposer(): void {
    composing.value = false;
    draft.reset();
    draft.contact_id = props.prefillContact?.id ?? '';
}

function saveDraft(): void {
    if (draft.title.trim() === '' && draft.body.trim() === '') {
        closeComposer();
        return;
    }
    draft.post(route('notes.store'), { preserveScroll: true, onSuccess: () => closeComposer() });
}

// Edit modal.
const editing = ref<NoteRow | null>(null);
const editOpen = ref(false);
const edit = useForm({ title: '', body: '', color: null as NoteColor, is_pinned: false, contact_id: '' as string, company_id: '' as string, owner_id: '' as string });

watch(editOpen, (open) => {
    if (open && editing.value) {
        edit.clearErrors();
        edit.title = editing.value.title ?? '';
        edit.body = editing.value.body ?? '';
        edit.color = editing.value.color;
        edit.is_pinned = editing.value.isPinned;
        edit.contact_id = editing.value.contact?.id ?? '';
        edit.company_id = editing.value.company?.id ?? '';
        edit.owner_id = editing.value.owner?.id ?? '';
    }
});

function openEdit(note: NoteRow): void {
    editing.value = note;
    editOpen.value = true;
}

function saveEdit(): void {
    if (!editing.value) {
        return;
    }
    edit.put(route('notes.update', { note: editing.value.id }), { preserveScroll: true, onSuccess: () => { editOpen.value = false; } });
}

const options = (items: Option[]) => [{ value: '', label: t('notes.field.notSet') }, ...items.map((item) => ({ value: item.id, label: item.name }))];
const ownerOptions = computed(() => [{ value: '', label: t('notes.common.unassigned') }, ...props.owners.map((item) => ({ value: item.id, label: item.name }))]);

// Quick pin toggle straight from the card.
function togglePin(note: NoteRow): void {
    router.put(route('notes.update', { note: note.id }), {
        title: note.title ?? '', body: note.body ?? '', color: note.color, is_pinned: !note.isPinned,
    }, { preserveScroll: true });
}

// Delete confirmation.
const removing = ref<NoteRow | null>(null);
function destroy(): void {
    if (!removing.value) {
        return;
    }
    router.delete(route('notes.destroy', { note: removing.value.id }), { preserveScroll: true, onFinish: () => { removing.value = null; } });
}

function noteMeta(note: NoteRow): string | null {
    return note.contact?.name ?? note.company?.name ?? null;
}
</script>

<template>
    <PageHeader :eyebrow="t('notes.eyebrow')" :title="t('notes.title')" :description="t('notes.description')">
        <template #actions>
            <form class="relative w-full sm:w-64" @submit.prevent="applySearch">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-subtle">
                    <Search class="size-4" aria-hidden="true" />
                </span>
                <input v-model="search" type="search" class="control pl-10" :placeholder="t('notes.search')" :aria-label="t('notes.search')" />
            </form>
        </template>
    </PageHeader>

    <!-- Take a note -->
    <div v-if="canCreate" class="mx-auto mb-6 w-full max-w-xl">
        <button
            v-if="!composing"
            type="button"
            class="flex w-full items-center gap-3 rounded-2xl border border-line bg-glass px-4 py-3 text-left text-ink-muted shadow-sm backdrop-blur transition hover:border-line-strong focus-visible:ring-2 focus-visible:ring-ring"
            @click="openComposer"
        >
            <Plus class="size-4 shrink-0" aria-hidden="true" />
            <span>{{ t('notes.composer.placeholder') }}</span>
        </button>

        <div v-else :class="['rounded-2xl border border-line-strong p-3 shadow-sm backdrop-blur transition', cardTint(draft.color)]">
            <form @submit.prevent="saveDraft">
                <input
                    v-model="draft.title"
                    class="w-full bg-transparent px-1 pb-1 text-base font-semibold text-ink outline-none placeholder:text-ink-subtle"
                    :placeholder="t('notes.composer.title')"
                    :aria-label="t('notes.field.title')"
                    maxlength="200"
                />
                <textarea
                    ref="bodyField"
                    v-model="draft.body"
                    class="min-h-18 w-full resize-y bg-transparent px-1 text-sm text-ink outline-none placeholder:text-ink-subtle"
                    :placeholder="t('notes.composer.body')"
                    :aria-label="t('notes.field.body')"
                    rows="3"
                    maxlength="20000"
                ></textarea>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5">
                        <button
                            v-for="swatch in SWATCHES"
                            :key="swatch ?? 'default'"
                            type="button"
                            :class="['size-6 rounded-full border transition focus-visible:ring-2 focus-visible:ring-ring', swatchTint(swatch), draft.color === swatch ? 'border-ink ring-2 ring-ring' : 'border-line-strong']"
                            :aria-label="colorLabel(swatch)"
                            :aria-pressed="draft.color === swatch"
                            @click="draft.color = swatch"
                        >
                            <Check v-if="draft.color === swatch" class="mx-auto size-3.5 text-ink" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button type="button" size="sm" variant="ghost" @click="closeComposer">{{ t('notes.composer.close') }}</Button>
                        <Button type="submit" size="sm" variant="primary" :loading="draft.processing">{{ t('notes.composer.add') }}</Button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Empty -->
    <div v-if="empty && !filtered" class="mx-auto mt-10 max-w-md text-center">
        <StickyNote class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <h2 class="mt-3 text-base font-semibold text-ink">{{ t('notes.empty.title') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ canCreate ? t('notes.empty.body') : t('notes.empty.viewOnly') }}</p>
    </div>

    <div v-else-if="empty && filtered" class="mx-auto mt-10 max-w-md text-center">
        <Search class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <h2 class="mt-3 text-base font-semibold text-ink">{{ t('notes.common.noMatches') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ t('notes.common.noMatchesBody') }}</p>
        <Button class="mt-3" size="sm" @click="clearSearch">{{ t('notes.common.clear') }}</Button>
    </div>

    <template v-else>
        <p class="eyebrow mb-3 text-ink-subtle" role="status">{{ count }}</p>

        <section v-if="pinned.length" class="mb-8">
            <h2 class="eyebrow mb-2 text-ink-subtle">{{ t('notes.sections.pinned') }}</h2>
            <div class="columns-1 gap-4 sm:columns-2 lg:columns-3 xl:columns-4">
                <article v-for="note in pinned" :key="note.id" :class="['group relative mb-4 break-inside-avoid rounded-2xl border border-line shadow-sm backdrop-blur transition hover:border-line-strong', cardTint(note.color)]">
                    <!-- card body -->
                    <component :is="note.editable ? 'button' : 'div'" :type="note.editable ? 'button' : undefined" class="block w-full px-4 pt-4 pb-2 text-left" @click="note.editable && openEdit(note)">
                        <span v-if="note.title" class="mb-1 block font-semibold text-ink">{{ note.title }}</span>
                        <span v-if="note.body" class="line-clamp-12 block whitespace-pre-line text-sm text-ink-muted">{{ note.body }}</span>
                    </component>
                    <footer class="flex items-center justify-between gap-2 px-3 pb-2">
                        <span v-if="noteMeta(note)" class="truncate rounded-full bg-control px-2 py-0.5 text-xs text-ink-subtle">{{ noteMeta(note) }}</span>
                        <span v-else class="text-xs text-ink-subtle">{{ format.date(note.createdAt) }}</span>
                        <div v-if="note.editable" class="flex items-center gap-0.5 opacity-0 transition group-focus-within:opacity-100 group-hover:opacity-100">
                            <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('notes.card.unpin')" @click.stop="togglePin(note)">
                                <Pin class="size-4 fill-current text-brand" aria-hidden="true" />
                            </button>
                            <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-danger focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('notes.card.delete')" @click.stop="removing = note">
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    </footer>
                </article>
            </div>
        </section>

        <section>
            <h2 v-if="pinned.length && others.length" class="eyebrow mb-2 text-ink-subtle">{{ t('notes.sections.others') }}</h2>
            <div class="columns-1 gap-4 sm:columns-2 lg:columns-3 xl:columns-4">
                <article v-for="note in others" :key="note.id" :class="['group relative mb-4 break-inside-avoid rounded-2xl border border-line shadow-sm backdrop-blur transition hover:border-line-strong', cardTint(note.color)]">
                    <component :is="note.editable ? 'button' : 'div'" :type="note.editable ? 'button' : undefined" class="block w-full px-4 pt-4 pb-2 text-left" @click="note.editable && openEdit(note)">
                        <span v-if="note.title" class="mb-1 block font-semibold text-ink">{{ note.title }}</span>
                        <span v-if="note.body" class="line-clamp-12 block whitespace-pre-line text-sm text-ink-muted">{{ note.body }}</span>
                    </component>
                    <footer class="flex items-center justify-between gap-2 px-3 pb-2">
                        <span v-if="noteMeta(note)" class="truncate rounded-full bg-control px-2 py-0.5 text-xs text-ink-subtle">{{ noteMeta(note) }}</span>
                        <span v-else class="text-xs text-ink-subtle">{{ format.date(note.createdAt) }}</span>
                        <div v-if="note.editable" class="flex items-center gap-0.5 opacity-0 transition group-focus-within:opacity-100 group-hover:opacity-100">
                            <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('notes.card.pin')" @click.stop="togglePin(note)">
                                <Pin class="size-4" aria-hidden="true" />
                            </button>
                            <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-danger focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('notes.card.delete')" @click.stop="removing = note">
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    </footer>
                </article>
            </div>
        </section>

        <Pagination class="mt-4" :meta="notes" />
    </template>

    <!-- Edit modal -->
    <Modal v-if="canCreate" v-model:open="editOpen" :title="t('notes.edit.heading')" :description="t('notes.edit.hint')" :persistent="edit.processing">
        <form id="notes-edit-form" class="grid gap-4" @submit.prevent="saveEdit">
            <Input v-model="edit.title" :label="t('notes.field.title')" :error="edit.errors.title" maxlength="200" autofocus />
            <Textarea v-model="edit.body" :label="t('notes.field.body')" :error="edit.errors.body" :rows="5" maxlength="20000" />
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="edit.contact_id" :options="options(contacts)" :label="t('notes.field.contact')" :error="edit.errors.contact_id" />
                <Select v-model="edit.company_id" :options="options(companies)" :label="t('notes.field.company')" :error="edit.errors.company_id" />
                <Select v-if="owners.length" v-model="edit.owner_id" :options="ownerOptions" :label="t('notes.field.owner')" :error="edit.errors.owner_id" class="sm:col-span-2" />
            </div>
            <div>
                <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('notes.colors.label') }}</span>
                <div class="flex items-center gap-1.5">
                    <button
                        v-for="swatch in SWATCHES"
                        :key="swatch ?? 'default'"
                        type="button"
                        :class="['size-7 rounded-full border transition focus-visible:ring-2 focus-visible:ring-ring', swatchTint(swatch), edit.color === swatch ? 'border-ink ring-2 ring-ring' : 'border-line-strong']"
                        :aria-label="colorLabel(swatch)"
                        :aria-pressed="edit.color === swatch"
                        @click="edit.color = swatch"
                    >
                        <Check v-if="edit.color === swatch" class="mx-auto size-4 text-ink" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="edit.processing" @click="editOpen = false">{{ t('notes.common.cancel') }}</Button>
            <Button type="submit" form="notes-edit-form" variant="primary" :loading="edit.processing">{{ t('notes.common.save') }}</Button>
        </template>
    </Modal>

    <!-- Delete confirm -->
    <Modal :open="removing !== null" :title="t('notes.remove.heading')" :description="t('notes.remove.body')" size="sm" @update:open="(value) => { if (!value) removing = null; }">
        <template #footer>
            <Button variant="ghost" @click="removing = null">{{ t('notes.common.cancel') }}</Button>
            <Button variant="danger" @click="destroy">{{ t('notes.card.delete') }}</Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Copy, ExternalLink, FileText, Globe, Link2, Mail, Pencil, Pin, Plus, Search, Trash2, Wrench } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { useToasts } from '@/Core/composables/useToasts';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import { displayAddress, linkHref, type LinkKind } from '@/Modules/Links/lib';

interface Option {
    id: string;
    name: string;
}

interface LinkRow {
    id: string;
    kind: LinkKind;
    title: string;
    address: string;
    description: string | null;
    folder: string | null;
    isPinned: boolean;
    owner: Option | null;
    editable: boolean;
    createdAt: string;
}

const props = defineProps<{
    links: Paginated<LinkRow>;
    filters: { search: string; kind: LinkKind | null; folder: string | null };
    kinds: LinkKind[];
    kindCounts: Partial<Record<LinkKind, number>>;
    folders: string[];
    owners: Option[];
    canCreate: boolean;
}>();

defineOptions({ layout: TenantLayout });

const { t } = useI18n();
const format = useFormat();
const toasts = useToasts();

const KIND_ICON: Record<LinkKind, Component> = { website: Globe, tool: Wrench, email: Mail, document: FileText, other: Link2 };

const pinned = computed(() => props.links.data.filter((link) => link.isPinned));
const others = computed(() => props.links.data.filter((link) => !link.isPinned));
const empty = computed(() => props.links.total === 0);
const filtered = computed(() => Boolean(props.filters.search || props.filters.kind || props.filters.folder));
const totalCount = computed(() => Object.values(props.kindCounts).reduce((sum, count) => sum + (count ?? 0), 0));
const count = computed(() => t(`links.count.${props.links.total === 1 ? 'one' : 'other'}`, { count: format.number(props.links.total) }));

// Filters.
const search = ref(props.filters.search);
function visit(changes: Partial<{ search: string; kind: LinkKind | null; folder: string | null }>): void {
    const next = { search: search.value, kind: props.filters.kind, folder: props.filters.folder, ...changes };
    router.get(route('links.index'), {
        search: next.search || undefined,
        kind: next.kind || undefined,
        folder: next.folder || undefined,
    }, { preserveState: true, replace: true });
}
function clearFilters(): void {
    search.value = '';
    router.get(route('links.index'), {}, { preserveState: true, replace: true });
}
const folderFilter = computed({
    get: () => props.filters.folder ?? '',
    set: (value: string | number | null) => visit({ folder: value === '' || value === null ? null : String(value) }),
});
const folderOptions = computed(() => [
    { value: '', label: t('links.filters.allFolders') },
    ...props.folders.map((folder) => ({ value: folder, label: folder })),
]);

// Create and edit share one form.
const editing = ref<LinkRow | null>(null);
const formOpen = ref(false);
const form = useForm({
    kind: 'website' as LinkKind,
    title: '',
    address: '',
    description: '',
    folder: '',
    is_pinned: false,
    owner_id: '' as string,
});

const kindOptions = computed(() => props.kinds.map((kind) => ({ value: kind, label: t(`links.kinds.${kind}`) })));
const ownerOptions = computed(() => [{ value: '', label: t('links.common.unassigned') }, ...props.owners.map((item) => ({ value: item.id, label: item.name }))]);
const isEmailKind = computed(() => form.kind === 'email');

watch(formOpen, (open) => {
    if (!open) {
        return;
    }
    form.clearErrors();
    const link = editing.value;
    form.kind = link?.kind ?? props.filters.kind ?? 'website';
    form.title = link?.title ?? '';
    form.address = link?.address ?? '';
    form.description = link?.description ?? '';
    form.folder = link?.folder ?? props.filters.folder ?? '';
    form.is_pinned = link?.isPinned ?? false;
    form.owner_id = link?.owner?.id ?? '';
});

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(link: LinkRow): void {
    editing.value = link;
    formOpen.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => { formOpen.value = false; } };
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };
        if (props.owners.length === 0) {
            delete payload.owner_id;
        }
        return payload;
    });

    if (editing.value) {
        form.put(route('links.update', { link: editing.value.id }), options);
    } else {
        form.post(route('links.store'), options);
    }
}

// Quick actions on a card.
function togglePin(link: LinkRow): void {
    router.put(route('links.update', { link: link.id }), {
        kind: link.kind, title: link.title, address: link.address, description: link.description, folder: link.folder, is_pinned: !link.isPinned,
    }, { preserveScroll: true });
}

const canCopy = typeof navigator !== 'undefined' && typeof navigator.clipboard?.writeText === 'function';
async function copy(link: LinkRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(link.address);
    } catch {
        return;
    }
    toasts.success(t('links.card.copied'));
}

const removing = ref<LinkRow | null>(null);
function destroy(): void {
    if (!removing.value) {
        return;
    }
    router.delete(route('links.destroy', { link: removing.value.id }), { preserveScroll: true, onFinish: () => { removing.value = null; } });
}

const sections = computed(() => [
    { key: 'pinned', heading: pinned.value.length && others.value.length ? t('links.sections.pinned') : null, items: pinned.value },
    { key: 'others', heading: pinned.value.length && others.value.length ? t('links.sections.others') : null, items: others.value },
].filter((section) => section.items.length > 0));
</script>

<template>
    <PageHeader :eyebrow="t('links.eyebrow')" :title="t('links.title')" :description="t('links.description')">
        <template #actions>
            <form class="relative w-full sm:w-64" @submit.prevent="visit({})">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-subtle">
                    <Search class="size-4" aria-hidden="true" />
                </span>
                <input v-model="search" type="search" class="control pl-10" :placeholder="t('links.search')" :aria-label="t('links.search')" />
            </form>
            <Button v-if="canCreate" variant="primary" @click="openCreate">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('links.actions.add') }}
            </Button>
        </template>
    </PageHeader>

    <!-- Kind and folder filters -->
    <div v-if="totalCount > 0 || filtered" class="mb-5 flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('links.filters.kind')">
            <button
                type="button"
                :aria-pressed="filters.kind === null"
                :class="['rounded-full border px-3 py-1 text-sm transition focus-visible:ring-2 focus-visible:ring-ring', filters.kind === null ? 'border-line-strong bg-active text-ink' : 'border-line text-ink-muted hover:text-ink']"
                @click="visit({ kind: null })"
            >
                {{ t('links.filters.all') }} <span class="text-ink-subtle">{{ format.number(totalCount) }}</span>
            </button>
            <button
                v-for="kind in kinds"
                :key="kind"
                type="button"
                :aria-pressed="filters.kind === kind"
                :class="['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition focus-visible:ring-2 focus-visible:ring-ring', filters.kind === kind ? 'border-line-strong bg-active text-ink' : 'border-line text-ink-muted hover:text-ink']"
                @click="visit({ kind })"
            >
                <component :is="KIND_ICON[kind]" class="size-3.5" aria-hidden="true" />
                {{ t(`links.kinds.${kind}`) }} <span class="text-ink-subtle">{{ format.number(kindCounts[kind] ?? 0) }}</span>
            </button>
        </div>
        <Select v-if="folders.length" v-model="folderFilter" :options="folderOptions" :aria-label="t('links.filters.folder')" class="w-full sm:ml-auto sm:w-52" />
    </div>

    <!-- Empty -->
    <div v-if="empty && !filtered" class="mx-auto mt-10 max-w-md text-center">
        <Link2 class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <h2 class="mt-3 text-base font-semibold text-ink">{{ t('links.empty.title') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ canCreate ? t('links.empty.body') : t('links.empty.viewOnly') }}</p>
        <Button v-if="canCreate" class="mt-4" variant="primary" @click="openCreate">
            <Plus class="size-4" aria-hidden="true" />
            {{ t('links.actions.add') }}
        </Button>
    </div>

    <div v-else-if="empty && filtered" class="mx-auto mt-10 max-w-md text-center">
        <Search class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <h2 class="mt-3 text-base font-semibold text-ink">{{ t('links.common.noMatches') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ t('links.common.noMatchesBody') }}</p>
        <Button class="mt-3" size="sm" @click="clearFilters">{{ t('links.common.clear') }}</Button>
    </div>

    <template v-else>
        <p class="eyebrow mb-3 text-ink-subtle" role="status">{{ count }}</p>

        <section v-for="section in sections" :key="section.key" class="mb-8">
            <h2 v-if="section.heading" class="eyebrow mb-2 text-ink-subtle">{{ section.heading }}</h2>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="link in section.items" :key="link.id" class="flex flex-col rounded-2xl border border-line bg-glass p-4 shadow-sm backdrop-blur transition hover:border-line-strong">
                    <div class="flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-ink">
                            <component :is="KIND_ICON[link.kind]" class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <a
                                v-if="linkHref(link.kind, link.address)"
                                :href="linkHref(link.kind, link.address) ?? undefined"
                                :target="link.kind === 'email' ? undefined : '_blank'"
                                rel="noopener noreferrer nofollow"
                                class="group inline-flex max-w-full items-center gap-1 font-semibold text-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span class="truncate">{{ link.title }}</span>
                                <ExternalLink v-if="link.kind !== 'email'" class="size-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />
                                <span v-if="link.kind !== 'email'" class="sr-only">{{ t('links.card.opensNewTab') }}</span>
                            </a>
                            <span v-else class="block truncate font-semibold text-ink">{{ link.title }}</span>
                            <span class="block truncate text-sm text-ink-subtle">{{ displayAddress(link.kind, link.address) }}</span>
                        </div>
                        <span v-if="link.isPinned" class="shrink-0">
                            <Pin class="size-4 fill-current text-brand" aria-hidden="true" />
                            <span class="sr-only">{{ t('links.card.pinned') }}</span>
                        </span>
                    </div>

                    <p v-if="link.description" class="mt-2 line-clamp-3 text-sm whitespace-pre-line text-ink-muted">{{ link.description }}</p>

                    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-3">
                        <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                            <button
                                v-if="link.folder"
                                type="button"
                                class="truncate rounded-full bg-control px-2 py-0.5 text-xs text-ink-subtle hover:text-ink focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-label="t('links.card.showFolder', { folder: link.folder })"
                                @click="visit({ folder: link.folder })"
                            >
                                {{ link.folder }}
                            </button>
                            <span v-if="owners.length && link.owner" class="truncate text-xs text-ink-subtle">{{ link.owner.name }}</span>
                        </div>
                        <div class="flex items-center gap-0.5">
                            <button v-if="canCopy" type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('links.card.copy')" :title="t('links.card.copy')" @click="copy(link)">
                                <Copy class="size-4" aria-hidden="true" />
                            </button>
                            <template v-if="link.editable">
                                <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="link.isPinned ? t('links.card.unpin') : t('links.card.pin')" :title="link.isPinned ? t('links.card.unpin') : t('links.card.pin')" @click="togglePin(link)">
                                    <Pin :class="['size-4', link.isPinned ? 'fill-current text-brand' : '']" aria-hidden="true" />
                                </button>
                                <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-ink focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('links.card.edit')" :title="t('links.card.edit')" @click="openEdit(link)">
                                    <Pencil class="size-4" aria-hidden="true" />
                                </button>
                                <button type="button" class="rounded-full p-1.5 text-ink-muted transition hover:bg-hover hover:text-danger focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('links.card.delete')" :title="t('links.card.delete')" @click="removing = link">
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </template>
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <Pagination class="mt-4" :meta="links" />
    </template>

    <!-- Create / edit -->
    <Modal v-if="canCreate" v-model:open="formOpen" :title="editing ? t('links.form.editHeading') : t('links.form.createHeading')" :description="t('links.form.hint')" :persistent="form.processing">
        <form id="links-form" class="grid gap-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.kind" :options="kindOptions" :label="t('links.field.kind')" :error="form.errors.kind" />
                <Input v-model="form.title" :label="t('links.field.title')" :error="form.errors.title" maxlength="150" required autofocus />
            </div>
            <Input
                v-model="form.address"
                :type="isEmailKind ? 'email' : 'url'"
                :label="isEmailKind ? t('links.field.email') : t('links.field.url')"
                :hint="isEmailKind ? undefined : t('links.field.urlHint')"
                :error="form.errors.address"
                :placeholder="isEmailKind ? t('links.field.emailPlaceholder') : t('links.field.urlPlaceholder')"
                :maxlength="isEmailKind ? 254 : 2048"
                autocomplete="off"
                required
            />
            <Textarea v-model="form.description" :label="t('links.field.description')" :error="form.errors.description" :rows="3" maxlength="500" optional />
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.folder" :label="t('links.field.folder')" :hint="t('links.field.folderHint')" :error="form.errors.folder" list="links-folders" maxlength="60" optional />
                <Select v-if="owners.length" v-model="form.owner_id" :options="ownerOptions" :label="t('links.field.owner')" :error="form.errors.owner_id" />
            </div>
            <datalist id="links-folders">
                <option v-for="folder in folders" :key="folder" :value="folder" />
            </datalist>
            <Checkbox v-model="form.is_pinned" :label="t('links.field.pinned')" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="formOpen = false">{{ t('links.common.cancel') }}</Button>
            <Button type="submit" form="links-form" variant="primary" :loading="form.processing">{{ t('links.common.save') }}</Button>
        </template>
    </Modal>

    <!-- Delete confirm -->
    <Modal :open="removing !== null" :title="t('links.remove.heading')" :description="t('links.remove.body')" size="sm" @update:open="(value) => { if (!value) removing = null; }">
        <template #footer>
            <Button variant="ghost" @click="removing = null">{{ t('links.common.cancel') }}</Button>
            <Button variant="danger" @click="destroy">{{ t('links.card.delete') }}</Button>
        </template>
    </Modal>
</template>

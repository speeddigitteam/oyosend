<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Film, Image, Pencil, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import Select from '@/Core/Components/Select.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import MediaEditDialog from '../../Components/MediaEditDialog.vue';
import MediaUploadDialog from '../../Components/MediaUploadDialog.vue';
import { sizeParts } from '../../Components/socialFormat';
import type { MediaRow, Paginated } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{
    media: Paginated<MediaRow>;
    filters: { kind: string | null; q: string | null };
    canManage: boolean;
    accept: { mimes: string[]; imageMaxKb: number; videoMaxKb: number };
}>();

const { t } = useI18n();
const format = useFormat();

const kind = ref(props.filters.kind ?? 'all');
const search = ref(props.filters.q ?? '');
const filtered = computed(() => kind.value !== 'all' || search.value.trim() !== '');
const kindOptions = computed(() => [
    { value: 'all', label: t('social.common.all') },
    { value: 'image', label: t('social.status.mediaKind.image') },
    { value: 'video', label: t('social.status.mediaKind.video') },
]);

const uploadOpen = ref(false);
const editing = ref<MediaRow | null>(null);
const editOpen = ref(false);
const deleting = ref<MediaRow | null>(null);
const deleteOpen = ref(false);
const busy = ref(false);

function apply(): void {
    router.get(route('social.media.index'), {
        kind: kind.value === 'all' ? undefined : kind.value,
        q: search.value.trim() || undefined,
    }, { preserveState: true, replace: true });
}

function clear(): void {
    kind.value = 'all';
    search.value = '';
    apply();
}

function edit(item: MediaRow): void {
    editing.value = item;
    editOpen.value = true;
}

function askDelete(item: MediaRow): void {
    deleting.value = item;
    deleteOpen.value = true;
}

function destroy(): void {
    if (!deleting.value) {
        return;
    }

    busy.value = true;
    router.delete(route('social.media.destroy', { media: deleting.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            deleteOpen.value = false;
        },
    });
}

function sizeText(bytes: number): string {
    const { unit, value } = sizeParts(bytes);

    return t(`social.units.${unit}`, { count: format.number(value) });
}

function dimensions(item: MediaRow): string | null {
    return item.width && item.height ? t('social.media.dimensions', { width: format.number(item.width), height: format.number(item.height) }) : null;
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="t('social.media.title')" :description="t('social.media.description')">
        <template v-if="canManage" #actions>
            <Button variant="primary" @click="uploadOpen = true">
                <template #icon><Upload class="size-4" aria-hidden="true" /></template>
                {{ t('social.media.upload.open') }}
            </Button>
        </template>
    </PageHeader>

    <Card flush>
        <form class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="apply">
            <Input v-model="search" type="search" :label="t('social.common.search')" maxlength="100" class="w-full sm:w-56" />
            <Select v-model="kind" :options="kindOptions" :label="t('social.media.kind')" class="w-full sm:w-44" />
            <Button type="submit" size="sm">{{ t('social.common.apply') }}</Button>
            <Button v-if="filtered" type="button" size="sm" variant="ghost" @click="clear">{{ t('social.common.clearFilters') }}</Button>
        </form>

        <ul v-if="media.data.length > 0" class="grid grid-cols-1 gap-3 p-5 min-[480px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-testid="media-grid">
            <li v-for="item in media.data" :key="item.id" class="flex min-w-0 flex-col rounded-card border border-line p-3">
                <div class="mb-3 flex aspect-video items-center justify-center overflow-hidden rounded-control bg-hover">
                    <img v-if="item.kind === 'image'" :src="item.url" :alt="item.altText ?? item.name" loading="lazy" class="size-full object-cover" />
                    <Film v-else class="size-8 text-ink-subtle" aria-hidden="true" />
                </div>
                <p class="truncate text-sm font-medium text-ink" :title="item.name">{{ item.name }}</p>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    {{ t(`social.status.mediaKind.${item.kind}`) }} · {{ sizeText(item.sizeBytes) }}<template v-if="dimensions(item)"> · {{ dimensions(item) }}</template>
                </p>
                <p v-if="item.inUse" class="mt-1 text-xs text-ink-muted">{{ t('social.media.inUse') }}</p>
                <p v-if="item.kind === 'image' && !item.altText" class="mt-1 text-xs text-ink-subtle">{{ t('social.media.noAlt') }}</p>
                <div v-if="canManage" class="mt-3 flex gap-1.5">
                    <Button size="sm" @click="edit(item)">
                        <template #icon><Pencil class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.common.edit') }}
                    </Button>
                    <Button v-if="!item.inUse" size="sm" variant="ghost" @click="askDelete(item)">
                        <template #icon><Trash2 class="size-3.5" aria-hidden="true" /></template>
                        {{ t('social.common.delete') }}
                    </Button>
                </div>
            </li>
        </ul>

        <EmptyState
            v-else
            :title="filtered ? t('social.common.noMatches') : t('social.media.empty')"
            :description="filtered ? t('social.common.noMatchesBody') : t('social.media.emptyBody')"
            compact
        >
            <template #icon><Image /></template>
            <Button v-if="canManage && !filtered" size="sm" variant="primary" @click="uploadOpen = true">{{ t('social.media.upload.open') }}</Button>
        </EmptyState>
        <Pagination :meta="media" />
    </Card>

    <MediaUploadDialog v-model:open="uploadOpen" :accept="accept" />
    <MediaEditDialog v-model:open="editOpen" :media="editing" />
    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('social.media.deleteTitle')"
        :description="t('social.media.deleteBody', { name: deleting?.name ?? '' })"
        :confirm-label="t('social.common.delete')"
        :loading="busy"
        @confirm="destroy"
    />
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

/**
 * Previous / next for Laravel's length-aware and cursor paginators. Pass the
 * paginator object itself; the "x–y of z" summary appears when it has totals.
 */
const props = defineProps<{
    meta: {
        prev_page_url: string | null;
        next_page_url: string | null;
        from?: number | null;
        to?: number | null;
        total?: number;
    };
}>();

const { t } = useI18n();
const format = useFormat();

const linkClass =
    'inline-flex h-8 items-center gap-1 rounded-control border border-line-strong bg-control px-2.5 text-[0.8125rem] font-medium text-ink shadow-[inset_0_1px_0_var(--glass-edge)] transition-colors hover:bg-control-hover';
const disabledClass =
    'inline-flex h-8 items-center gap-1 rounded-control border border-line px-2.5 text-[0.8125rem] font-medium text-ink-subtle opacity-55';

function summary(): string {
    if (props.meta.total === undefined || !props.meta.from || !props.meta.to) {
        return '';
    }

    return t('common.table.range', {
        from: format.number(props.meta.from),
        to: format.number(props.meta.to),
        total: format.number(props.meta.total),
    });
}
</script>

<template>
    <nav
        v-if="meta.prev_page_url || meta.next_page_url"
        class="flex items-center justify-between gap-4 border-t border-line px-5 py-3"
        :aria-label="t('common.table.pagination')"
    >
        <p class="tabular text-[0.8125rem] text-ink-subtle">{{ summary() }}</p>

        <div class="flex items-center gap-2">
            <Link v-if="meta.prev_page_url" :href="meta.prev_page_url" :class="linkClass" rel="prev">
                <ChevronLeft class="size-4" aria-hidden="true" />
                {{ t('common.table.previous') }}
            </Link>
            <span v-else :class="disabledClass" aria-disabled="true">
                <ChevronLeft class="size-4" aria-hidden="true" />
                {{ t('common.table.previous') }}
            </span>

            <Link v-if="meta.next_page_url" :href="meta.next_page_url" :class="linkClass" rel="next">
                {{ t('common.table.next') }}
                <ChevronRight class="size-4" aria-hidden="true" />
            </Link>
            <span v-else :class="disabledClass" aria-disabled="true">
                {{ t('common.table.next') }}
                <ChevronRight class="size-4" aria-hidden="true" />
            </span>
        </div>
    </nav>
</template>

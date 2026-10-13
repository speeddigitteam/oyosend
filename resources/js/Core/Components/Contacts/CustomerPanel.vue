<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, UsersRound } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Skeleton from '@/Core/Components/Skeleton.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

export interface CustomerRecordItem { id: string; title: string; status: string | null; tone: BadgeTone | null; detail: string | null; at: string | null; url: string | null }
export interface CustomerRecordSection { key: string; module: string; label: string; items: CustomerRecordItem[]; total: number; viewAllUrl: string | null; actions: Array<{ key: string; label: string; url: string }> }

/**
 * Customer 360 (ADR 0020): what every app the member may use holds about one contact. Each app decides what the
 * member may see; this panel only shows what the server returned and links back to the owning app.
 */
const props = defineProps<{ contactId: string; contactName?: string | null }>();
const { t } = useI18n();
const format = useFormat();
const sections = ref<CustomerRecordSection[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
let request: AbortController | null = null;

async function load(): Promise<void> {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(route('core.contacts.records', { contact: props.contactId }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(t('contacts.customer360.loadError'));
        }

        const body = (await response.json()) as { sections?: CustomerRecordSection[] };
        sections.value = Array.isArray(body.sections) ? body.sections : [];
    } catch (reason: unknown) {
        if (!(reason instanceof DOMException && reason.name === 'AbortError')) {
            error.value = reason instanceof Error ? reason.message : t('contacts.customer360.loadError');
        }
    } finally {
        if (request === controller) {
            loading.value = false;
            request = null;
        }
    }
}

watch(() => props.contactId, () => void load(), { immediate: true });
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <Card :title="t('contacts.customer360.title')" :description="contactName ? t('contacts.customer360.description', { name: contactName }) : undefined" :aria-busy="loading || undefined">
        <template #actions><UsersRound class="size-4 text-ink-subtle" aria-hidden="true" /></template>

        <div v-if="loading && sections.length === 0" class="grid gap-3" role="status" :aria-label="t('contacts.customer360.loading')">
            <Skeleton v-for="line in 4" :key="line" class="h-4" />
        </div>
        <div v-else-if="error" class="grid justify-items-start gap-2">
            <p class="text-sm text-danger" role="alert">{{ error }}</p>
            <Button size="sm" @click="load">{{ t('contacts.customer360.retry') }}</Button>
        </div>
        <EmptyState v-else-if="sections.length === 0" :title="t('contacts.customer360.empty')" compact><template #icon><UsersRound /></template></EmptyState>

        <div v-else class="grid gap-5">
            <section v-for="section in sections" :key="section.key" :aria-labelledby="`customer-section-${section.key}`">
                <header class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 :id="`customer-section-${section.key}`" class="text-sm font-semibold text-ink">
                        {{ section.label }}
                        <span class="ml-1 font-mono text-xs font-normal text-ink-subtle">{{ format.number(section.total) }}</span>
                    </h3>
                    <div v-if="section.actions.length" class="flex flex-wrap gap-1">
                        <Button v-for="action in section.actions" :key="action.key" size="sm" variant="ghost" :href="action.url">{{ action.label }}</Button>
                    </div>
                </header>

                <p v-if="section.items.length === 0" class="text-xs text-ink-subtle">{{ t('contacts.customer360.sectionEmpty') }}</p>
                <ul v-else class="divide-y divide-line rounded-control border border-line">
                    <li v-for="item in section.items" :key="item.id">
                        <component
                            :is="item.url ? Link : 'div'"
                            v-bind="item.url ? { href: item.url } : {}"
                            class="flex min-w-0 items-start justify-between gap-3 px-3 py-2"
                            :class="item.url ? 'transition hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand' : ''"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-ink">{{ item.title }}</span>
                                <span v-if="item.detail || item.at" class="mt-0.5 block truncate text-xs text-ink-subtle">
                                    {{ [item.detail, item.at ? format.date(item.at) : null].filter(Boolean).join(' · ') }}
                                </span>
                            </span>
                            <Badge v-if="item.status" :tone="item.tone ?? 'neutral'" class="shrink-0">{{ item.status }}</Badge>
                        </component>
                    </li>
                </ul>

                <p v-if="section.items.length < section.total" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                    <span>{{ t('contacts.customer360.shown', { shown: format.number(section.items.length), total: format.number(section.total) }) }}</span>
                    <Link v-if="section.viewAllUrl" :href="section.viewAllUrl" class="inline-flex items-center gap-1 text-brand-text hover:underline">
                        {{ t('contacts.customer360.viewAll') }}<ArrowUpRight class="size-3.5" aria-hidden="true" />
                    </Link>
                </p>
            </section>
        </div>
    </Card>
</template>

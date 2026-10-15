<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Inbox, Mail, Phone } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Pagination from '@/Core/Components/Pagination.vue';
import SegmentedControl from '@/Core/Components/SegmentedControl.vue';
import { useFormat } from '@/Core/composables/useFormat';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import type { Paginated } from '@/Core/types';
import { useAbilities } from '../../Components/abilities';
import WebsiteNav from '../../Components/Website/WebsiteNav.vue';

defineOptions({ layout: PlatformLayout });

type Status = 'new' | 'handled' | 'all';

interface Enquiry {
    id: string;
    name: string;
    email: string;
    phone: string | null;
    company: string | null;
    topic: string;
    message: string;
    locale: string;
    status: 'new' | 'handled';
    createdAt: string;
    handledAt: string | null;
    handledBy: string | null;
}

const props = defineProps<{
    enquiries: Paginated<Enquiry>;
    counts: { new: number; handled: number };
    filters: { status: Status; search: string };
}>();

const { t, te } = useI18n();
const format = useFormat();
const { allows } = useAbilities();
const canManage = computed(() => allows('platform.website.manage'));

const status = ref<Status>(props.filters.status);
const search = ref(props.filters.search);
const busy = ref<string | null>(null);
const deleting = ref<Enquiry | null>(null);

const statuses = computed(() => [
    { value: 'new' as const, label: `${t('platformWebsite.messages.filter.new')} (${format.number(props.counts.new)})` },
    { value: 'handled' as const, label: `${t('platformWebsite.messages.filter.handled')} (${format.number(props.counts.handled)})` },
    { value: 'all' as const, label: t('platformWebsite.messages.filter.all') },
]);

function apply(): void {
    router.get(
        route('platform.website.messages.index'),
        { status: status.value, search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function topicLabel(topic: string): string {
    return te(`platformWebsite.messages.topics.${topic}`) ? t(`platformWebsite.messages.topics.${topic}`) : topic;
}

function replyHref(enquiry: Enquiry): string {
    return `mailto:${enquiry.email}?subject=${encodeURIComponent(`Re: ${topicLabel(enquiry.topic)}`)}`;
}

function telHref(phone: string): string {
    return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

function setHandled(enquiry: Enquiry, handled: boolean): void {
    busy.value = enquiry.id;
    const url = route(handled ? 'platform.website.messages.handled' : 'platform.website.messages.reopen', { enquiry: enquiry.id });
    const options = { preserveScroll: true, onFinish: () => (busy.value = null) };

    if (handled) {
        router.post(url, {}, options);
    } else {
        router.delete(url, options);
    }
}

function remove(): void {
    const enquiry = deleting.value;

    if (!enquiry) {
        return;
    }

    busy.value = enquiry.id;
    router.delete(route('platform.website.messages.destroy', { enquiry: enquiry.id }), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <PageHeader :eyebrow="t('platformWebsite.eyebrow')" :title="t('platformWebsite.messages.title')" :description="t('platformWebsite.messages.description')">
        <template #actions>
            <!-- The website is not part of this app: a full page load, in a new tab. -->
            <Button :href="route('central.home')" native target="_blank" rel="noopener">{{ t('platformWebsite.open') }}</Button>
        </template>
    </PageHeader>

    <WebsiteNav current="messages" />

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <SegmentedControl v-model="status" :options="statuses" :label="t('platformWebsite.messages.filter.label')" @update:model-value="apply" />
        <form class="flex min-w-60 flex-1 items-end gap-2 sm:max-w-md sm:flex-none" @submit.prevent="apply">
            <Input
                v-model="search"
                type="search"
                class="min-w-0 flex-1"
                :label="t('platformWebsite.messages.search')"
                :placeholder="t('platformWebsite.messages.searchPlaceholder')"
                maxlength="100"
            />
            <Button type="submit">{{ t('platformWebsite.messages.search') }}</Button>
        </form>
    </div>

    <Card v-if="enquiries.data.length === 0">
        <EmptyState :title="t('platformWebsite.messages.empty')" :description="t('platformWebsite.messages.emptyBody')" compact>
            <template #icon><Inbox /></template>
        </EmptyState>
    </Card>

    <ul v-else class="grid gap-3 xl:grid-cols-2" data-testid="enquiries">
        <li v-for="enquiry in enquiries.data" :key="enquiry.id">
            <Card class="h-full" :data-testid="`enquiry-${enquiry.id}`">
                <template #header>
                    <div class="min-w-0">
                        <h2 class="text-[0.9375rem] font-semibold wrap-break-word text-ink">{{ enquiry.name }}</h2>
                        <p v-if="enquiry.company" class="mt-0.5 text-[0.8125rem] wrap-break-word text-ink-muted">{{ enquiry.company }}</p>
                    </div>
                </template>
                <template #actions>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Badge tone="info">{{ topicLabel(enquiry.topic) }}</Badge>
                        <Badge :tone="enquiry.status === 'new' ? 'accent' : 'ok'" dot>
                            {{ enquiry.status === 'new' ? t('platformWebsite.messages.filter.new') : t('platformWebsite.messages.filter.handled') }}
                        </Badge>
                    </div>
                </template>

                <p class="text-sm leading-relaxed whitespace-pre-line wrap-break-word text-ink">{{ enquiry.message }}</p>

                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-[0.8125rem]">
                    <a :href="replyHref(enquiry)" class="inline-flex items-center gap-1.5 font-medium text-brand-text hover:underline">
                        <Mail class="size-3.5" aria-hidden="true" />{{ enquiry.email }}
                    </a>
                    <a v-if="enquiry.phone" :href="telHref(enquiry.phone)" class="tabular inline-flex items-center gap-1.5 font-medium text-brand-text hover:underline">
                        <Phone class="size-3.5" aria-hidden="true" />{{ enquiry.phone }}
                    </a>
                </div>

                <p class="mt-3 text-xs text-ink-subtle">
                    {{ t('platformWebsite.messages.received', { when: format.date(enquiry.createdAt, 'datetime') }) }}
                    · {{ t(`platformWebsite.messages.language.${enquiry.locale === 'bn' ? 'bn' : 'en'}`) }}
                    <template v-if="enquiry.handledAt">
                        ·
                        {{
                            enquiry.handledBy
                                ? t('platformWebsite.messages.answeredBy', { name: enquiry.handledBy, when: format.relative(enquiry.handledAt) })
                                : t('platformWebsite.messages.answered', { when: format.relative(enquiry.handledAt) })
                        }}
                    </template>
                </p>

                <template v-if="canManage" #footer>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <Button variant="ghost" size="sm" :disabled="busy === enquiry.id" @click="deleting = enquiry">{{ t('platformWebsite.messages.delete') }}</Button>
                        <Button v-if="enquiry.status === 'handled'" size="sm" :loading="busy === enquiry.id" @click="setHandled(enquiry, false)">
                            {{ t('platformWebsite.messages.reopen') }}
                        </Button>
                        <Button v-else variant="primary" size="sm" :loading="busy === enquiry.id" @click="setHandled(enquiry, true)">
                            {{ t('platformWebsite.messages.markHandled') }}
                        </Button>
                    </div>
                </template>
            </Card>
        </li>
    </ul>

    <Pagination v-if="enquiries.last_page > 1" class="mt-4" :meta="enquiries" />

    <ConfirmDialog
        :open="deleting !== null"
        :title="t('platformWebsite.messages.deleteTitle')"
        :description="t('platformWebsite.messages.deleteBody')"
        :confirm-label="t('platformWebsite.messages.delete')"
        :loading="busy !== null"
        @update:open="(open) => !open && (deleting = null)"
        @confirm="remove"
    />
</template>

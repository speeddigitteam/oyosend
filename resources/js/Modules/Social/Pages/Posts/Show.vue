<script setup lang="ts">
import { router, usePage, useForm } from '@inertiajs/vue3';
import { CalendarClock, Check, KeyRound, Pencil, RotateCcw, Send, Trash2, Undo2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import PlatformIcon from '../../Components/PlatformIcon.vue';
import RejectDialog from '../../Components/RejectDialog.vue';
import ScheduleDialog from '../../Components/ScheduleDialog.vue';
import SocialBadge from '../../Components/SocialBadge.vue';
import { displayTag, lengthState, safeUrl } from '../../Components/socialFormat';
import TikTokSettingsSummary from '../../Components/TikTokSettingsSummary.vue';
import type { PostDetail, PostPreview, PostTargetDetail } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ post: PostDetail }>();

const { t } = useI18n();
const format = useFormat();
const { can } = useAccess();
const page = usePage();
const workspaceTimezone = computed(() => String(page.props.app.timezone));

const scheduleOpen = ref(false);
const rescheduleOpen = ref(false);
const rejectOpen = ref(false);
const deleteOpen = ref(false);
const unscheduleOpen = ref(false);
const busy = ref(false);

const title = computed(() => props.post.title ?? (props.post.excerpt !== '' ? props.post.excerpt : t('social.common.untitled')));
const timezone = computed(() => workspaceTimezone.value);
const link = computed(() => safeUrl(props.post.linkUrl));
const previews = computed<PostPreview[]>(() => (props.post.status === 'published' ? [] : props.post.previews));
const hasIssues = computed(() => previews.value.some((item) => item.issues.length > 0));
const failedTargets = computed(() => props.post.targets.filter((target) => target.status === 'failed'));

// Failures that only the account owner can fix by connecting the Page again (missing permission, expired token).
const REAUTH_CODES = ['reauthorization_required', 'facebook_permission_missing'];

function needsReconnect(target: PostTargetDetail): boolean {
    return target.status === 'failed' && target.lastErrorCode !== null && REAUTH_CODES.includes(target.lastErrorCode);
}

// A video is processed by the platform for a while: follow it without asking people to refresh.
const inFlight = computed(() => props.post.targets.some((target) => target.status === 'publishing' || target.status === 'processing'));
let poll: ReturnType<typeof setInterval> | undefined;

watch(inFlight, (active) => {
    clearInterval(poll);
    poll = active ? setInterval(() => router.reload({ only: ['post'] }), 20000) : undefined;
}, { immediate: true });
onBeforeUnmount(() => clearInterval(poll));

function act(name: string, params: Record<string, string>, after?: () => void): void {
    busy.value = true;
    router.post(route(name, params), {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            after?.();
        },
    });
}

function destroy(): void {
    busy.value = true;
    router.delete(route('social.posts.destroy', { post: props.post.id }), {
        onFinish: () => {
            busy.value = false;
            deleteOpen.value = false;
        },
    });
}

function retry(target: PostTargetDetail): void {
    act('social.posts.targets.retry', { post: props.post.id, target: target.id });
}

// Team comments are internal: they are never published.
const comment = useForm({ body: '' });

function addComment(): void {
    comment.transform((data) => ({ body: data.body.trim() }));
    comment.post(route('social.posts.comments.store', { post: props.post.id }), {
        preserveScroll: true,
        onSuccess: () => comment.reset(),
    });
}

function removeComment(id: string): void {
    router.delete(route('social.posts.comments.destroy', { post: props.post.id, comment: id }), { preserveScroll: true });
}

function lengthClass(preview: PostPreview): string {
    const state = lengthState(preview.length, preview.maxLength);

    return state === 'over' ? 'text-danger' : state === 'near' ? 'text-warn' : 'text-ink-subtle';
}

function when(value: string | null): string {
    return value ? format.date(value, 'datetime') : t('social.common.dash');
}
</script>

<template>
    <PageHeader :eyebrow="t('social.eyebrow')" :title="title">
        <template #actions>
            <Button v-if="post.abilities.edit" :href="route('social.posts.edit', { post: post.id })">
                <template #icon><Pencil class="size-4" aria-hidden="true" /></template>
                {{ t('social.common.edit') }}
            </Button>
            <Button v-if="post.abilities.submit && post.approval.required" :loading="busy" @click="act('social.posts.submit', { post: post.id })">
                <template #icon><Send class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.submit') }}
            </Button>
            <Button v-if="post.abilities.approve" variant="primary" :loading="busy" @click="act('social.posts.approve', { post: post.id })">
                <template #icon><Check class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.approve') }}
            </Button>
            <Button v-if="post.abilities.reject" @click="rejectOpen = true">
                <template #icon><X class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.reject') }}
            </Button>
            <Button v-if="post.abilities.schedule" variant="primary" @click="scheduleOpen = true">
                <template #icon><CalendarClock class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.schedule') }}
            </Button>
            <Button v-if="post.abilities.reschedule" @click="rescheduleOpen = true">
                <template #icon><CalendarClock class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.reschedule') }}
            </Button>
            <Button v-if="post.abilities.unschedule" @click="unscheduleOpen = true">
                <template #icon><Undo2 class="size-4" aria-hidden="true" /></template>
                {{ t('social.show.unschedule') }}
            </Button>
            <Button v-if="post.abilities.delete" variant="danger" @click="deleteOpen = true">
                <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                {{ t('social.common.delete') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-3 lg:grid-cols-3">
        <div class="grid min-w-0 content-start gap-3 lg:col-span-2">
            <Card :title="t('social.show.content')">
                <template #actions>
                    <Badge v-if="post.aiAssisted" tone="info" :title="t('socialAi.badgeHint')" data-testid="ai-assisted">{{ t('socialAi.badge') }}</Badge>
                    <SocialBadge kind="post" :value="post.status" />
                </template>

                <p v-if="post.body" class="text-sm leading-6 break-words whitespace-pre-wrap text-ink" data-testid="post-body">{{ post.body }}</p>
                <p v-else class="text-sm text-ink-muted">{{ t('social.show.noText') }}</p>

                <p v-if="link" class="mt-3 text-sm break-all">
                    <a :href="link" target="_blank" rel="noopener noreferrer" class="text-brand-text hover:underline">{{ link }}</a>
                </p>
                <p v-else-if="post.linkUrl" class="mt-3 text-sm break-all text-ink-muted">{{ post.linkUrl }}</p>

                <p v-if="post.hashtags.length > 0" class="mt-3 text-sm break-words text-ink-muted">{{ post.hashtags.map(displayTag).join(' ') }}</p>

                <ul v-if="post.media.length > 0" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <li v-for="item in post.media" :key="item.id" class="min-w-0">
                        <div class="flex aspect-video items-center justify-center overflow-hidden rounded-control bg-hover">
                            <img v-if="item.kind === 'image'" :src="item.url" :alt="item.altText ?? item.name" loading="lazy" class="size-full object-cover" />
                            <span v-else class="px-2 text-center text-xs text-ink-muted">{{ t('social.status.mediaKind.video') }}</span>
                        </div>
                        <p class="mt-1 truncate text-xs text-ink-subtle">{{ item.name }}</p>
                    </li>
                </ul>
            </Card>

            <Card :title="t('social.show.accounts')">
                <p v-if="post.targets.length === 0" class="text-sm text-ink-muted">{{ t('social.show.noAccounts') }}</p>

                <ul v-else class="divide-y divide-line">
                    <li v-for="target in post.targets" :key="target.id" class="grid gap-2 py-3 first:pt-0 last:pb-0" data-testid="target">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium text-ink">{{ target.accountName }}</span>
                            <span class="inline-flex items-center gap-1 text-xs text-ink-subtle">
                                <PlatformIcon :platform="target.platform" class="size-3.5 shrink-0" />{{ target.platformLabel }}
                            </span>
                            <SocialBadge kind="target" :value="target.status" />
                            <SocialBadge v-if="target.accountStatus !== 'connected'" kind="account" :value="target.accountStatus" />
                        </div>

                        <p v-if="target.bodyOverride" class="text-[0.8125rem] break-words whitespace-pre-wrap text-ink-muted">
                            <span class="eyebrow mr-1">{{ t('social.show.variant') }}</span>{{ target.bodyOverride }}
                        </p>

                        <TikTokSettingsSummary v-if="target.hasPublishOptions && target.platform === 'tiktok'" :options="target.publishOptions ?? null" />

                        <p v-if="target.publishedAt" class="text-[0.8125rem] text-ink-muted">
                            {{ t('social.show.publishedAt', { when: when(target.publishedAt) }) }}
                            <template v-if="safeUrl(target.externalUrl)">
                                <a :href="safeUrl(target.externalUrl)!" target="_blank" rel="noopener noreferrer" class="ml-1 text-brand-text hover:underline">{{ t('social.show.viewOnPlatform') }}</a>
                            </template>
                        </p>

                        <p v-if="target.nextAttemptAt && target.status === 'pending'" class="text-[0.8125rem] text-ink-muted">
                            {{ t('social.show.nextAttempt', { when: when(target.nextAttemptAt) }) }}
                        </p>

                        <div v-if="target.status === 'processing'" class="grid gap-1 rounded-control bg-info-soft px-3 py-2 text-[0.8125rem] text-info" role="status" data-testid="processing">
                            <p class="font-medium">{{ target.remoteState === 'processing' ? t('socialPublishing.processing.processingTitle') : t('socialPublishing.processing.uploadingTitle') }}</p>
                            <p>{{ target.remoteState === 'processing' ? t('socialPublishing.processing.processingBody') : t('socialPublishing.processing.uploadingBody') }}</p>
                            <p v-if="target.lastError" class="text-xs break-words">{{ t('socialPublishing.processing.retrying', { reason: target.lastError }) }}</p>
                            <p v-if="target.processingSince" class="text-xs">{{ t('socialPublishing.processing.since', { when: when(target.processingSince) }) }}</p>
                        </div>

                        <div v-if="needsReconnect(target)" class="grid gap-2 rounded-control border border-warn px-3 py-2 text-[0.8125rem]" role="status" data-testid="reconnect">
                            <p class="font-medium text-ink">{{ t('socialPublishing.reconnect.title') }}</p>
                            <p class="text-ink-muted">{{ t('socialPublishing.reconnect.body') }}</p>
                            <div>
                                <Button size="sm" :href="route('social.accounts.index')">
                                    <template #icon><KeyRound class="size-3.5" aria-hidden="true" /></template>
                                    {{ t('socialPublishing.reconnect.action') }}
                                </Button>
                            </div>
                        </div>

                        <div v-if="target.status === 'failed'" class="grid gap-2 rounded-control bg-danger-soft px-3 py-2 text-[0.8125rem] text-danger" role="status">
                            <p v-if="target.lastError" class="break-words">{{ target.lastError }}</p>
                            <p v-if="target.needsManualCheck" class="font-medium" data-testid="manual-check">{{ t('social.show.manualCheck') }}</p>
                            <p class="text-xs">{{ t('social.show.attempts', { count: format.number(target.attempts) }) }}</p>
                        </div>

                        <div v-if="target.status === 'failed' && target.canRetry && post.abilities.retry">
                            <Button size="sm" :loading="busy" @click="retry(target)">
                                <template #icon><RotateCcw class="size-3.5" aria-hidden="true" /></template>
                                {{ t('social.show.retry') }}
                            </Button>
                        </div>

                        <details v-if="target.attemptLog.length > 0" class="text-[0.8125rem]">
                            <summary class="cursor-pointer text-ink-muted hover:text-ink">{{ t('social.show.attemptLog', { count: format.number(target.attemptLog.length) }) }}</summary>
                            <ul class="mt-2 grid gap-1.5">
                                <li v-for="entry in target.attemptLog" :key="entry.attempt" class="flex flex-wrap items-center gap-2">
                                    <span class="tabular text-xs text-ink-subtle">{{ when(entry.at) }}</span>
                                    <SocialBadge kind="attempt" :value="entry.outcome" />
                                    <span v-if="entry.message" class="min-w-0 break-words text-ink-muted">{{ entry.message }}</span>
                                </li>
                            </ul>
                        </details>
                    </li>
                </ul>
            </Card>

            <Card v-if="previews.length > 0" :title="t('social.show.checks')" :description="hasIssues ? t('social.show.checksIssues') : t('social.show.checksOk')">
                <ul class="grid gap-3">
                    <li v-for="preview in previews" :key="preview.targetId" class="rounded-control border border-line p-3" data-testid="preview">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm font-medium text-ink">{{ preview.accountName }} <span class="inline-flex items-center gap-1 text-xs font-normal text-ink-subtle"><PlatformIcon :platform="preview.platform" class="size-3.5 shrink-0" />{{ preview.platformLabel }}</span></span>
                            <span :class="['tabular text-xs', lengthClass(preview)]">
                                <template v-if="preview.maxLength > 0">{{ t('social.form.length', { count: format.number(preview.length), max: format.number(preview.maxLength) }) }}</template>
                                <template v-else>{{ t('social.form.lengthNoLimit', { count: format.number(preview.length) }) }}</template>
                            </span>
                        </div>
                        <p v-if="preview.text" class="mt-2 text-[0.8125rem] break-words whitespace-pre-wrap text-ink-muted">{{ preview.text }}</p>
                        <ul v-if="preview.issues.length > 0" class="mt-2 grid gap-1 text-[0.8125rem] text-warn">
                            <li v-for="issue in preview.issues" :key="issue">{{ issue }}</li>
                        </ul>
                        <ul v-if="(preview.warnings ?? []).length > 0" class="mt-2 grid gap-1 text-xs text-ink-muted" data-testid="preview-warnings">
                            <li v-for="warning in preview.warnings" :key="warning">{{ warning }}</li>
                        </ul>
                    </li>
                </ul>
            </Card>

            <Card :title="t('social.show.comments')" :description="t('social.show.commentsHint')">
                <p v-if="post.comments.length === 0" class="text-sm text-ink-muted">{{ t('social.show.noComments') }}</p>
                <ul v-else class="grid gap-3">
                    <li v-for="item in post.comments" :key="item.id" class="rounded-control border border-line p-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                            <span class="font-medium text-ink">{{ item.author?.name ?? t('social.common.unknownPerson') }}</span>
                            <span class="tabular">{{ when(item.at) }}</span>
                            <Button
                                v-if="item.mine || can('social.posts.approve')"
                                size="sm"
                                variant="ghost"
                                class="ml-auto"
                                @click="removeComment(item.id)"
                            >
                                {{ t('social.common.delete') }}
                            </Button>
                        </div>
                        <p class="mt-1 text-sm break-words whitespace-pre-wrap text-ink">{{ item.body }}</p>
                    </li>
                </ul>

                <form v-if="post.abilities.comment" class="mt-4 grid gap-2" novalidate @submit.prevent="addComment">
                    <Textarea v-model="comment.body" :label="t('social.show.addComment')" maxlength="2000" :rows="3" :error="comment.errors.body" />
                    <div>
                        <Button type="submit" size="sm" variant="primary" :loading="comment.processing" :disabled="comment.body.trim() === ''">
                            {{ t('social.show.postComment') }}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>

        <div class="grid min-w-0 content-start gap-3">
            <Card :title="t('social.show.details')">
                <dl class="grid gap-3 text-[0.8125rem]">
                    <div>
                        <dt class="eyebrow">{{ t('social.fields.status') }}</dt>
                        <dd class="mt-1"><SocialBadge kind="post" :value="post.status" /></dd>
                    </div>
                    <div v-if="post.aiAssisted">
                        <dt class="eyebrow">{{ t('socialAi.badge') }}</dt>
                        <dd class="mt-1 text-ink-muted" data-testid="ai-assisted-note">{{ t('socialAi.badgeHint') }}</dd>
                    </div>
                    <div v-if="post.postType && post.postType !== 'post'">
                        <dt class="eyebrow">{{ t('socialPublishing.postType.label') }}</dt>
                        <dd class="mt-1 text-ink" data-testid="post-type">{{ t(`socialPublishing.postType.${post.postType}`) }}</dd>
                    </div>
                    <div v-if="post.scheduledFor">
                        <dt class="eyebrow">{{ t('social.show.scheduledFor') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.scheduledFor) }}</dd>
                    </div>
                    <div v-if="post.publishedAt">
                        <dt class="eyebrow">{{ t('social.show.publishedOn') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.publishedAt) }}</dd>
                    </div>
                    <div v-if="post.owner">
                        <dt class="eyebrow">{{ t('social.show.author') }}</dt>
                        <dd class="mt-1 text-ink">{{ post.owner.name }}</dd>
                    </div>
                    <div v-if="post.campaign">
                        <dt class="eyebrow">{{ t('social.fields.campaign') }}</dt>
                        <dd class="mt-1 text-ink">{{ post.campaign.name }}</dd>
                    </div>
                    <div v-if="post.category">
                        <dt class="eyebrow">{{ t('social.fields.category') }}</dt>
                        <dd class="mt-1 text-ink">{{ post.category.name }}</dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('social.show.created') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.createdAt) }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="post.approval.required || post.submittedAt || post.rejectedAt" :title="t('social.show.approval')">
                <dl class="grid gap-3 text-[0.8125rem]">
                    <div v-if="post.submittedAt">
                        <dt class="eyebrow">{{ t('social.show.submittedAt') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.submittedAt) }}</dd>
                    </div>
                    <div v-if="post.approvedAt">
                        <dt class="eyebrow">{{ t('social.show.approvedAt') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.approvedAt) }}</dd>
                    </div>
                    <div v-if="post.rejectedAt">
                        <dt class="eyebrow">{{ t('social.show.rejectedAt') }}</dt>
                        <dd class="tabular mt-1 text-ink">{{ when(post.rejectedAt) }}</dd>
                        <dd v-if="post.rejectionReason" class="mt-1 break-words text-ink-muted" data-testid="rejection-reason">{{ post.rejectionReason }}</dd>
                    </div>
                </dl>
                <p v-if="post.status === 'pending_approval' && !post.abilities.approve" class="mt-3 text-[0.8125rem] text-ink-muted" data-testid="four-eyes">
                    {{ t('social.show.fourEyes') }}
                </p>
                <Badge v-if="post.approval.required" tone="info" class="mt-3">{{ t('social.show.approvalRequired') }}</Badge>
            </Card>

            <Card v-if="failedTargets.length > 0 && post.abilities.retry" :title="t('social.show.retryPolicyTitle')">
                <p class="text-[0.8125rem] text-ink-muted">{{ t('social.show.retryPolicy') }}</p>
            </Card>
        </div>
    </div>

    <ScheduleDialog
        v-model:open="scheduleOpen"
        :post-id="post.id"
        mode="schedule"
        :initial="post.scheduledForLocal"
        :timezone="timezone"
    />
    <ScheduleDialog
        v-model:open="rescheduleOpen"
        :post-id="post.id"
        mode="reschedule"
        :initial="post.scheduledForLocal"
        :timezone="timezone"
    />
    <RejectDialog v-model:open="rejectOpen" :post-id="post.id" />

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="t('social.show.deleteTitle')"
        :description="t('social.show.deleteBody')"
        :confirm-label="t('social.common.delete')"
        :loading="busy"
        @confirm="destroy"
    />
    <ConfirmDialog
        v-model:open="unscheduleOpen"
        tone="default"
        :title="t('social.show.unscheduleTitle')"
        :description="t('social.show.unscheduleBody')"
        :confirm-label="t('social.show.unschedule')"
        :loading="busy"
        @confirm="act('social.posts.unschedule', { post: post.id }, () => (unscheduleOpen = false))"
    />
</template>

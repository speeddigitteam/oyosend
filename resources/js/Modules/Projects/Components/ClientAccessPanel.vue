<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Copy, ExternalLink, Share2, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge, { type BadgeTone } from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import ConfirmDialog from '@/Core/Components/ConfirmDialog.vue';
import Input from '@/Core/Components/Input.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { Option } from './types';
export interface ClientAccessRow {
    id: string;
    contact: Option & { email: string };
    company: Option | null;
    status: 'invited' | 'active' | 'revoked' | 'expired';
    canComment: boolean;
    canViewFinancials: boolean;
    invitedAt: string;
    acceptedAt: string | null;
    expiresAt: string | null;
    revocable: boolean;
}
const props = defineProps<{ projectId: string; accesses: ClientAccessRow[] }>();
const emit = defineEmits<{ share: [] }>();
const page = usePage(); const { t } = useI18n(); const format = useFormat(); const copied = ref(false); const copyError = ref(''); const revokeId = ref<string | null>(null); const revokePending = ref(false); const revokeError = ref('');
const invitation = computed(() => page.flash?.clientAccessInvitation ?? null);
const tones: Record<ClientAccessRow['status'], BadgeTone> = { invited: 'info', active: 'ok', revoked: 'neutral', expired: 'warn' };
watch(() => invitation.value?.url, () => { copied.value = false; copyError.value = ''; });
async function copy(): Promise<void> {
    if (!invitation.value) return;
    copied.value = false;
    copyError.value = '';
    try {
        if (!navigator.clipboard) throw new Error('Clipboard API unavailable');
        await navigator.clipboard.writeText(invitation.value.url);
        copied.value = true;
    } catch {
        copyError.value = t('projects.portal.copyFailed');
    }
}
function chooseRevoke(id: string): void { if (revokePending.value) return; revokeError.value = ''; revokeId.value = id; }
function firstError(errors: Record<string, string>): string { return errors.client_access ?? errors.status ?? Object.values(errors)[0] ?? ''; }
function revoke(): void {
    if (!revokeId.value || revokePending.value) return;
    revokePending.value = true;
    revokeError.value = '';
    router.delete(route('projects.client-access.destroy', { project: props.projectId, clientAccess: revokeId.value }), {
        preserveScroll: true,
        onSuccess: () => { revokeId.value = null; },
        onError: (errors) => { revokeError.value = firstError(errors); },
        onFinish: () => { revokePending.value = false; },
    });
}
</script>
<template><Card :title="t('projects.portal.accessTitle')" flush><template #actions><Button size="sm" variant="primary" @click="emit('share')"><template #icon><Share2 class="size-4" aria-hidden="true" /></template>{{ t('projects.portal.share') }}</Button></template><div v-if="invitation" class="border-b border-line bg-brand-soft px-5 py-4"><p class="mb-2 text-sm font-medium text-brand-text">{{ t('projects.portal.copyNow') }}</p><div class="flex flex-wrap gap-2"><Input :model-value="invitation.url" readonly class="min-w-56 flex-1" :label="t('projects.portal.invitationLink')" /><Button size="sm" class="self-end" @click="copy"><template #icon><Copy class="size-4" aria-hidden="true" /></template>{{ t(copied ? 'projects.portal.copied' : 'projects.portal.copy') }}</Button><Button size="sm" variant="ghost" class="self-end" :href="invitation.url" target="_blank" rel="noopener"><template #icon><ExternalLink class="size-4" aria-hidden="true" /></template>{{ t('projects.portal.open') }}</Button></div><p v-if="copyError" class="mt-2 text-sm text-danger" role="alert">{{ copyError }}</p></div><div v-if="accesses.length === 0" class="px-5 py-4"><p class="text-sm font-medium text-ink">{{ t('projects.portal.noAccess') }}</p><p class="mt-0.5 text-[0.8125rem] text-ink-subtle">{{ t('projects.portal.noAccessBody') }}</p></div><ul v-else class="divide-y divide-line"><li v-for="access in accesses" :key="access.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><p class="font-medium text-ink">{{ access.contact.name }}</p><Badge :tone="tones[access.status]" dot>{{ t(`projects.portal.status.${access.status}`) }}</Badge></div><p class="mt-1 truncate text-xs text-ink-subtle">{{ access.contact.email }}<template v-if="access.company"> · {{ access.company.name }}</template></p><p class="mt-1 text-xs text-ink-subtle">{{ t('projects.portal.permissions', { comments: t(access.canComment ? 'projects.common.yes' : 'projects.common.no'), financials: t(access.canViewFinancials ? 'projects.common.yes' : 'projects.common.no') }) }}<template v-if="access.expiresAt"> · {{ t('projects.portal.expiresOn', { date: format.date(access.expiresAt) }) }}</template></p></div><Button v-if="access.revocable" icon size="sm" variant="ghost" :aria-label="t('projects.portal.revokeFor', { name: access.contact.name })" :disabled="revokePending" @click="chooseRevoke(access.id)"><template #icon><Trash2 class="size-4" aria-hidden="true" /></template></Button></li></ul></Card><ConfirmDialog :open="revokeId !== null" :title="t('projects.portal.revoke')" :description="t('projects.portal.revokeBody')" :confirm-label="t('projects.portal.revoke')" :loading="revokePending" @update:open="!$event && !revokePending && (revokeId = null)" @confirm="revoke"><p v-if="revokeError" class="text-sm text-danger" role="alert">{{ revokeError }}</p></ConfirmDialog></template>

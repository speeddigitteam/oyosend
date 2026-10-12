<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Lock, LockOpen, Plus, UserPlus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import Select from '@/Core/Components/Select.vue';
import { useAccess } from '@/Core/composables/useAccess';
import { route } from '@/Core/lib/route';
import type { AgentOption, ConversationDetail } from './types';

/**
 * The people side of one conversation: whose contact it is, who owns it, whether it is open and its tags.
 * Every control is shown only when the server said the person may use it (`can`); the server checks again.
 */
const props = defineProps<{
    conversation: ConversationDetail;
    agents: AgentOption[];
    phoneContacts: { id: string; name: string; phone: string | null }[];
    can: { reply: boolean; assign: boolean; assignOthers: boolean; manage: boolean; isAgent: boolean; createContact: boolean };
}>();

const { t } = useI18n();
const page = usePage();
const { can: allowed } = useAccess();

const base = computed(() => ({ conversation: props.conversation.id }));
const memberId = computed(() => page.props.auth?.membership?.id ?? null);
const error = ref('');
const busy = ref(false);

function post(name: string, data: Record<string, string | string[] | boolean | null> = {}): void {
    error.value = '';
    busy.value = true;
    router.post(route(name, base.value), data, {
        preserveScroll: true,
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? t('whatsapp.panel.failed');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

const mine = computed(() => memberId.value !== null && props.conversation.owner?.id === memberId.value);
const assigneeId = ref(props.conversation.owner?.id ?? '');
const assignOptions = computed(() => [
    { value: '', label: t('whatsapp.panel.unassigned') },
    ...props.agents.map((agent) => ({ value: agent.id, label: agent.isAvailable ? agent.name : t('whatsapp.panel.agentAway', { name: agent.name }) })),
]);

function saveAssignee(): void {
    post('whatsapp_marketing.inbox.assign', { assignee_id: assigneeId.value === '' ? null : assigneeId.value });
}

const closed = computed(() => props.conversation.status === 'closed');

// Tags.
const tagDraft = ref('');
const tagError = computed(() => {
    const value = tagDraft.value.trim();

    return value.length > 30 ? t('whatsapp.panel.tagTooLong') : '';
});

function saveTags(tags: string[]): void {
    post('whatsapp_marketing.inbox.tags', { tags });
}

function addTag(): void {
    const value = tagDraft.value.trim().toLowerCase();

    if (value === '' || tagError.value !== '' || props.conversation.tags.includes(value) || props.conversation.tags.length >= 10) {
        return;
    }

    saveTags([...props.conversation.tags, value]);
    tagDraft.value = '';
}

// Contact.
const newName = ref('');
</script>

<template>
    <div class="grid gap-3">
        <p v-if="error" role="alert" class="rounded-control bg-danger-soft px-3 py-2 text-sm text-danger">{{ error }}</p>

        <Card :title="t('whatsapp.panel.contact')">
            <div v-if="conversation.contact" class="grid gap-1 text-sm">
                <p class="font-medium text-ink wrap-anywhere">{{ conversation.contact.name }}</p>
                <p class="tabular text-ink-muted">{{ conversation.phone }}</p>
                <Link
                    v-if="allowed('core.contacts.view')"
                    :href="route('core.contacts.index', { search: conversation.contact.name })"
                    class="text-brand-text hover:underline"
                >
                    {{ t('whatsapp.panel.openContact') }}
                </Link>
                <p v-if="!conversation.contactPhoneMatches" class="mt-1 text-warn" data-testid="phone-mismatch">
                    {{ t('whatsapp.panel.phoneMismatch') }}
                </p>
            </div>
            <div v-else class="grid gap-3 text-sm" data-testid="no-contact">
                <p class="tabular text-ink-muted">{{ conversation.phone }}</p>
                <p v-if="conversation.profileName" class="text-ink-muted wrap-anywhere">{{ t('whatsapp.panel.profileName', { name: conversation.profileName }) }}</p>
                <p class="text-ink">{{ t('whatsapp.panel.notInContacts') }}</p>

                <template v-if="can.manage">
                    <ul v-if="phoneContacts.length > 0" class="grid gap-2" :aria-label="t('whatsapp.panel.matches')">
                        <li v-for="match in phoneContacts" :key="match.id" class="flex flex-wrap items-center justify-between gap-2">
                            <span class="wrap-anywhere">{{ match.name }}</span>
                            <Button size="sm" variant="secondary" :disabled="busy" @click="post('whatsapp_marketing.inbox.contact', { contact_id: match.id })">
                                {{ t('whatsapp.panel.link') }}
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-ink-subtle">{{ t('whatsapp.panel.noMatches') }}</p>

                    <form v-if="can.createContact" class="grid gap-2" @submit.prevent="post('whatsapp_marketing.inbox.contact', { create: true, name: newName })">
                        <Input v-model="newName" :label="t('whatsapp.panel.contactName')" :hint="t('whatsapp.panel.contactNameHint')" optional maxlength="120" />
                        <div>
                            <Button type="submit" size="sm" variant="secondary" :loading="busy">
                                <template #icon><UserPlus class="size-4" aria-hidden="true" /></template>
                                {{ t('whatsapp.panel.createContact') }}
                            </Button>
                        </div>
                    </form>
                </template>
            </div>
        </Card>

        <Card :title="t('whatsapp.panel.assignment')">
            <div class="grid gap-3 text-sm">
                <p>
                    <span class="text-ink-muted">{{ t('whatsapp.panel.owner') }}</span>
                    <span class="ml-1 font-medium" :class="conversation.owner ? 'text-ink' : 'text-ink-subtle'">
                        {{ conversation.owner?.name ?? t('whatsapp.panel.unassigned') }}
                    </span>
                    <Badge v-if="mine" class="ml-2" tone="brand">{{ t('whatsapp.panel.you') }}</Badge>
                </p>
                <p v-if="conversation.botPaused" class="text-xs text-ink-subtle">{{ t('whatsapp.panel.botQuiet') }}</p>

                <form v-if="can.assignOthers" class="grid gap-2" @submit.prevent="saveAssignee">
                    <Select v-model="assigneeId" :options="assignOptions" :label="t('whatsapp.panel.assignTo')" />
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" size="sm" variant="secondary" :loading="busy">{{ t('whatsapp.panel.assign') }}</Button>
                        <Button
                            v-if="can.isAgent && !mine"
                            size="sm"
                            variant="ghost"
                            :disabled="busy"
                            @click="post('whatsapp_marketing.inbox.claim')"
                        >
                            {{ t('whatsapp.panel.assignToMe') }}
                        </Button>
                    </div>
                </form>
                <div v-else-if="can.assign && can.isAgent && !mine">
                    <Button size="sm" variant="secondary" :loading="busy" @click="post('whatsapp_marketing.inbox.claim')">
                        {{ t('whatsapp.panel.assignToMe') }}
                    </Button>
                </div>
                <p v-else-if="can.assign && !can.isAgent && !mine" class="text-xs text-ink-subtle">{{ t('whatsapp.panel.notAgent') }}</p>
            </div>
        </Card>

        <Card :title="t('whatsapp.panel.status')">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <Badge :tone="closed ? 'neutral' : 'ok'" dot>{{ t(`whatsapp.conversationStatus.${conversation.status}`) }}</Badge>
                <Button
                    v-if="can.manage"
                    size="sm"
                    variant="secondary"
                    :loading="busy"
                    @click="post('whatsapp_marketing.inbox.status', { status: closed ? 'open' : 'closed' })"
                >
                    <template #icon>
                        <LockOpen v-if="closed" class="size-4" aria-hidden="true" />
                        <Lock v-else class="size-4" aria-hidden="true" />
                    </template>
                    {{ closed ? t('whatsapp.panel.reopen') : t('whatsapp.panel.close') }}
                </Button>
            </div>
        </Card>

        <Card :title="t('whatsapp.panel.tags')">
            <p v-if="conversation.tags.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.panel.noTags') }}</p>
            <ul v-else class="flex flex-wrap gap-1.5" :aria-label="t('whatsapp.panel.tags')">
                <li v-for="tag in conversation.tags" :key="tag">
                    <Badge tone="info">
                        <span class="wrap-anywhere">{{ tag }}</span>
                        <button
                            v-if="can.manage"
                            type="button"
                            class="-mr-1 ml-1 inline-flex rounded-chip p-0.5 hover:bg-hover focus-visible:outline-2"
                            :aria-label="t('whatsapp.panel.removeTag', { tag })"
                            :disabled="busy"
                            @click="saveTags(conversation.tags.filter((item) => item !== tag))"
                        >
                            <X class="size-3" aria-hidden="true" />
                        </button>
                    </Badge>
                </li>
            </ul>
            <form v-if="can.manage && conversation.tags.length < 10" class="mt-3 flex items-end gap-2" @submit.prevent="addTag">
                <Input v-model="tagDraft" class="min-w-0 flex-1" :label="t('whatsapp.panel.addTag')" :error="tagError" maxlength="40" />
                <Button type="submit" size="sm" variant="secondary" :disabled="busy || tagDraft.trim() === ''">
                    <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                    {{ t('whatsapp.panel.add') }}
                </Button>
            </form>
        </Card>
    </div>
</template>

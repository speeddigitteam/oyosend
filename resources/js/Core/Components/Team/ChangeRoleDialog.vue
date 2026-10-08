<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { Member, RoleOption } from './types';

/**
 * Gives a member a different role. The list only holds roles the person
 * making the change may hand out, so a member's current role can be missing
 * from it - it is shown above the picker for that reason.
 */
const props = defineProps<{
    member: Member | null;
    /** Roles this person may hand out. */
    roles: RoleOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const formId = useId();
const selectId = useId();

const form = useForm({ role_id: '' });

const options = computed(() => props.roles.map((role) => ({ value: role.id, label: role.name })));
const unchanged = computed(() => form.role_id === '' || form.role_id === props.member?.role?.id);

// The server reports "you cannot change this member" under a key of its own.
const memberError = computed(() => (form.errors as Record<string, string | undefined>).member);

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.clearErrors();

        return;
    }

    const current = props.member?.role?.id;

    form.role_id = current !== undefined && props.roles.some((role) => role.id === current) ? current : '';

    // The dialog focuses its close button by default; start on the picker instead.
    await nextTick();
    requestAnimationFrame(() => document.getElementById(selectId)?.focus());
});

function submit(): void {
    if (!props.member || unchanged.value) {
        return;
    }

    form.patch(route('core.members.update', { membership: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="t('team.changeRole.title', { name: member?.name })"
        :description="t('team.changeRole.description')"
        size="sm"
        :persistent="form.processing"
    >
        <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <dl v-if="member" class="glass-well flex items-baseline justify-between gap-4 rounded-control px-3 py-2.5">
                <dt class="eyebrow shrink-0">{{ t('team.changeRole.current') }}</dt>
                <dd class="min-w-0 truncate text-sm text-ink" :title="member.role?.name">
                    {{ member.role?.name ?? t('team.members.noRole') }}
                </dd>
            </dl>

            <Select
                :id="selectId"
                v-model="form.role_id"
                name="role_id"
                :label="t('team.changeRole.role')"
                :options="options"
                :placeholder="t('team.changeRole.placeholder')"
                :hint="t('team.changeRole.hint')"
                :error="form.errors.role_id ?? memberError"
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('common.actions.cancel') }}</Button>
            <Button type="submit" variant="primary" :form="formId" :loading="form.processing" :disabled="unchanged">
                {{ t('team.changeRole.submit') }}
            </Button>
        </template>
    </Modal>
</template>

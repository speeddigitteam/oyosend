<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import { route } from '@/Core/lib/route';
import type { RoleOption } from './types';

/**
 * Invites an address to the workspace with a role. Inviting an address that
 * already has an open invitation replaces it, which is how "send again" works:
 * pass the old invitation's email and role name as `prefill`.
 */
const props = defineProps<{
    /** Roles this person may hand out. */
    roles: RoleOption[];
    prefill?: { email: string; role: string } | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const page = usePage();

const formId = useId();
const roleId = useId();
const emailInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({ email: '', role_id: '' });

const options = computed(() => props.roles.map((role) => ({ value: role.id, label: role.name })));

watch(open, async (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    form.email = props.prefill?.email ?? '';
    // With a single role there is nothing to choose.
    form.role_id = props.roles.find((role) => role.name === props.prefill?.role)?.id ?? (props.roles.length === 1 ? props.roles[0]!.id : '');

    // The dialog focuses its close button by default; start in the form instead.
    await nextTick();
    requestAnimationFrame(() => {
        if (form.email === '') {
            emailInput.value?.focus();
        } else {
            document.getElementById(roleId)?.focus();
        }
    });
});

function submit(): void {
    form.post(route('core.invitations.store'), {
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
        :title="t('team.invite.title')"
        :description="t('team.invite.description', { workspace: page.props.tenant?.name })"
        :persistent="form.processing"
    >
        <p v-if="roles.length === 0" class="text-sm text-ink-muted">{{ t('team.invite.noRoles') }}</p>

        <form v-else :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Input
                ref="emailInput"
                v-model="form.email"
                type="email"
                name="email"
                :label="t('team.invite.email')"
                :error="form.errors.email"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                inputmode="email"
                maxlength="255"
                required
            />

            <Select
                :id="roleId"
                v-model="form.role_id"
                name="role_id"
                :label="t('team.invite.role')"
                :options="options"
                :placeholder="t('team.invite.rolePlaceholder')"
                :hint="t('team.invite.roleHint')"
                :error="form.errors.role_id"
                required
            />
        </form>

        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">
                {{ roles.length === 0 ? t('common.actions.close') : t('common.actions.cancel') }}
            </Button>
            <Button v-if="roles.length > 0" type="submit" variant="primary" :form="formId" :loading="form.processing">
                {{ t('team.invite.submit') }}
            </Button>
        </template>
    </Modal>
</template>

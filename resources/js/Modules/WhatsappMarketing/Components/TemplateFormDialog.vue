<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import type { TemplateRow } from './types';
import { type ButtonForm, buildButtons, countTemplateVariables } from './whatsappFormat';

/**
 * Record a template that exists in Meta's WhatsApp Manager. Name and language identify it there, so they can
 * only be set when it is recorded. Nothing is submitted to Meta from here.
 */
const props = defineProps<{
    template: TemplateRow | null;
    categories: string[];
    statuses: string[];
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const format = useFormat();

function initial() {
    const template = props.template;

    return {
        name: template?.name ?? '',
        language: template?.language ?? 'en',
        category: (template?.category ?? 'utility') as string,
        header: template?.header?.text ?? '',
        body: template?.body ?? '',
        footer: template?.footer?.text ?? '',
        buttons: (template?.buttons ?? []).map((button): ButtonForm => ({ type: button.type, text: button.text, value: button.value ?? '' })),
        status: (template?.status ?? 'pending') as string,
        status_reason: template?.statusReason ?? '',
    };
}

const form = useForm(initial());

watch(
    () => [open.value, props.template?.id] as const,
    ([isOpen]) => {
        if (isOpen) {
            form.defaults(initial());
            form.reset();
            form.clearErrors();
        }
    },
);

const editing = computed(() => props.template !== null);
const detected = computed(() => countTemplateVariables(form.body));
const categoryOptions = computed(() => props.categories.map((value) => ({ value, label: t(`whatsapp.templateCategory.${value}`) })));
const statusOptions = computed(() => props.statuses.map((value) => ({ value, label: t(`whatsapp.templateStatus.${value}`) })));
const buttonTypes = computed(() => ['quick_reply', 'url', 'phone_number'].map((value) => ({ value, label: t(`whatsapp.buttonType.${value}`) })));

function addButton(): void {
    if (form.buttons.length < 3) {
        form.buttons.push({ type: 'quick_reply', text: '', value: '' });
    }
}

function buttonError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`buttons.${index}.${field}`];
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    form
        .transform((data) => {
            const payload: Record<string, unknown> = {
                category: data.category,
                header: data.header.trim() === '' ? null : data.header.trim(),
                body: data.body,
                footer: data.footer.trim() === '' ? null : data.footer.trim(),
                buttons: buildButtons(data.buttons),
                status: data.status,
                status_reason: data.status === 'rejected' || data.status === 'paused' || data.status === 'disabled' ? data.status_reason.trim() || null : null,
            };

            if (!editing.value) {
                payload.name = data.name.trim();
                payload.language = data.language.trim();
            }

            return payload as typeof data;
        });

    if (props.template) {
        form.put(route('whatsapp_marketing.templates.update', { template: props.template.id }), options);
    } else {
        form.post(route('whatsapp_marketing.templates.store'), options);
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        size="lg"
        :title="editing ? t('whatsapp.templates.editTitle') : t('whatsapp.templates.createTitle')"
        :description="t('whatsapp.templates.formDescription')"
        :persistent="form.processing"
    >
        <form id="whatsapp-template" class="grid gap-4" novalidate @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
                <Input v-model="form.name" :label="t('whatsapp.templates.name')" :hint="t('whatsapp.templates.nameHint')" :error="form.errors.name" :disabled="editing" mono maxlength="255" />
                <Input v-model="form.language" :label="t('whatsapp.templates.language')" :hint="t('whatsapp.templates.languageHint')" :error="form.errors.language" :disabled="editing" mono maxlength="10" />
            </div>
            <Select v-model="form.category" :options="categoryOptions" :label="t('whatsapp.templates.category')" :error="form.errors.category" />
            <Input v-model="form.header" :label="t('whatsapp.templates.header')" :hint="t('whatsapp.templates.headerHint')" :error="form.errors.header" optional maxlength="60" />
            <Textarea
                v-model="form.body"
                :label="t('whatsapp.templates.body')"
                :hint="t('whatsapp.templates.bodyHint', { example: '{{1}}' })"
                :error="form.errors.body"
                :rows="5"
                maxlength="1024"
            />
            <p class="-mt-2 text-xs text-ink-subtle">{{ t('whatsapp.templates.detected', { count: format.number(detected) }) }}</p>
            <Input v-model="form.footer" :label="t('whatsapp.templates.footer')" :error="form.errors.footer" optional maxlength="60" />

            <fieldset class="grid gap-3">
                <legend class="eyebrow mb-1">{{ t('whatsapp.templates.buttons') }}</legend>
                <p v-if="form.buttons.length === 0" class="text-sm text-ink-subtle">{{ t('whatsapp.templates.noButtons') }}</p>
                <div v-for="(button, index) in form.buttons" :key="index" class="grid gap-2 rounded-control border border-line p-3 sm:grid-cols-[10rem_1fr_auto]">
                    <Select v-model="button.type" :options="buttonTypes" :label="t('whatsapp.templates.buttonType')" />
                    <Input v-model="button.text" :label="t('whatsapp.templates.buttonText')" :error="buttonError(index, 'text')" maxlength="25" />
                    <div class="flex items-end">
                        <Button size="sm" variant="ghost" @click="form.buttons.splice(index, 1)">
                            <template #icon><Trash2 class="size-4" aria-hidden="true" /></template>
                            {{ t('whatsapp.templates.removeButton') }}
                        </Button>
                    </div>
                    <Input
                        v-if="button.type !== 'quick_reply'"
                        v-model="button.value"
                        class="sm:col-span-3"
                        :label="button.type === 'url' ? t('whatsapp.templates.buttonUrl') : t('whatsapp.templates.buttonPhone')"
                        :error="buttonError(index, 'value')"
                        maxlength="2000"
                    />
                </div>
                <div v-if="form.buttons.length < 3">
                    <Button size="sm" variant="secondary" @click="addButton">
                        <template #icon><Plus class="size-4" aria-hidden="true" /></template>
                        {{ t('whatsapp.templates.addButton') }}
                    </Button>
                </div>
            </fieldset>

            <div class="grid gap-3">
                <Select v-model="form.status" :options="statusOptions" :label="t('whatsapp.templates.status')" :hint="t('whatsapp.templates.statusHint')" :error="form.errors.status" />
                <Input
                    v-if="['rejected', 'paused', 'disabled'].includes(form.status)"
                    v-model="form.status_reason"
                    :label="t('whatsapp.templates.statusReason')"
                    :error="form.errors.status_reason"
                    optional
                    maxlength="300"
                />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('whatsapp.common.cancel') }}</Button>
            <Button type="submit" form="whatsapp-template" variant="primary" :loading="form.processing">{{ t('whatsapp.common.save') }}</Button>
        </template>
    </Modal>
</template>

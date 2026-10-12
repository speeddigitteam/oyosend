<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import BlockEditor from '../../Components/BlockEditor.vue';
import EmailPreview from '../../Components/EmailPreview.vue';
import { newBlock } from '../../Components/emailFormat';
import type { EditorOptions, TemplateRow } from '../../Components/types';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ template: TemplateRow | null; editor: EditorOptions }>();

const { t } = useI18n();

const form = useForm({
    name: props.template?.name ?? '',
    subject: props.template?.subject ?? '',
    preheader: props.template?.preheader ?? '',
    blocks: props.template?.blocks ?? [newBlock('heading'), newBlock('text')],
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const invalid = computed(() => form.name.trim() === '' || form.blocks.length === 0);

function save(): void {
    form.transform((data) => ({
        name: data.name.trim(),
        subject: data.subject.trim() === '' ? null : data.subject.trim(),
        preheader: data.preheader.trim() === '' ? null : data.preheader.trim(),
        blocks: data.blocks,
    }));

    if (props.template) {
        form.put(route('email_marketing.templates.update', { template: props.template.id }), { preserveScroll: true });
    } else {
        form.post(route('email_marketing.templates.store'), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('emailMarketing.eyebrow')"
        :title="template ? t('emailMarketing.templates.edit') : t('emailMarketing.templates.add')"
        :description="t('emailMarketing.templates.formDescription')"
    />

    <form class="grid gap-3 lg:grid-cols-3" novalidate @submit.prevent="save">
        <div class="grid content-start gap-3 lg:col-span-2">
            <Card :title="t('emailMarketing.campaigns.details')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.name" :label="t('emailMarketing.fields.name')" maxlength="120" required :error="errors.name" />
                    <Input v-model="form.subject" :label="t('emailMarketing.fields.subject')" maxlength="200" optional :error="errors.subject" />
                    <Input
                        v-model="form.preheader"
                        class="sm:col-span-2"
                        :label="t('emailMarketing.fields.preheader')"
                        maxlength="200"
                        optional
                        :error="errors.preheader"
                    />
                </div>
            </Card>
            <Card :title="t('emailMarketing.campaigns.content')" :description="t('emailMarketing.campaigns.contentDescription')">
                <BlockEditor v-model="form.blocks" :variables="editor.variables" :errors="errors" :max="editor.maxBlocks" :max-text="editor.maxText" />
            </Card>
            <div class="flex flex-wrap items-center gap-2">
                <Button type="submit" variant="primary" :loading="form.processing" :disabled="invalid">{{ t('emailMarketing.common.save') }}</Button>
                <Button variant="ghost" :href="route('email_marketing.templates.index')">{{ t('emailMarketing.common.cancel') }}</Button>
            </div>
        </div>
        <Card :title="t('emailMarketing.preview.title')" class="self-start lg:sticky lg:top-4">
            <EmailPreview :blocks="form.blocks" :subject="form.subject" :preheader="form.preheader" />
        </Card>
    </form>
</template>

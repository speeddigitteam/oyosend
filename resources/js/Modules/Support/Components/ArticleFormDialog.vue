<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import type { Option } from './types';

export interface ArticleDraft {
    id: string;
    title: string;
    summary: string | null;
    body: string;
    categoryId: string | null;
}

/** Creates an article (as a draft) or edits one. Every save of changed text adds a version. */
const props = defineProps<{ article: ArticleDraft | null; categories: Option[]; defaultCategoryId?: string | null }>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const form = useForm({ title: '', summary: '', body: '', category_id: '' });

const categoryOptions = computed(() => [{ value: '', label: t('support.kb.uncategorised') }, ...props.categories.map((category) => ({ value: category.id, label: category.name }))]);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.defaults({
        title: props.article?.title ?? '',
        summary: props.article?.summary ?? '',
        body: props.article?.body ?? '',
        category_id: props.article?.categoryId ?? props.defaultCategoryId ?? '',
    }).reset();
    form.clearErrors();
});

function save(): void {
    form.transform((data) => ({ ...data, category_id: data.category_id === '' ? null : data.category_id }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.article) {
        form.put(route('support.kb.articles.update', { article: props.article.id }), options);
    } else {
        form.post(route('support.kb.articles.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" size="lg" :title="t(article ? 'support.kb.editArticle' : 'support.kb.newArticle')" :persistent="form.processing">
        <form id="support-article-form" class="grid gap-4" @submit.prevent="save">
            <Input v-model="form.title" :label="t('support.kb.articleTitle')" :error="form.errors.title" required maxlength="200" />
            <Input v-model="form.summary" :label="t('support.kb.summary')" :error="form.errors.summary" optional maxlength="300" />
            <Select v-model="form.category_id" :options="categoryOptions" :label="t('support.fields.category')" :error="form.errors.category_id" />
            <Textarea v-model="form.body" :rows="12" :label="t('support.kb.body')" :hint="t('support.kb.bodyHint')" :error="form.errors.body" required maxlength="100000" />
        </form>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="open = false">{{ t('support.common.cancel') }}</Button>
            <Button form="support-article-form" type="submit" variant="primary" :loading="form.processing">{{ t('support.common.save') }}</Button>
        </template>
    </Modal>
</template>

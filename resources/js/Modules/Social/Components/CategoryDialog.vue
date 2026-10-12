<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import { route } from '@/Core/lib/route';
import SocialFormDialog from './SocialFormDialog.vue';
import type { CategoryRow } from './types';

/** Creates a category or renames `category`. */
const props = defineProps<{ open: boolean; category: CategoryRow | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '' });

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ name: props.category?.name ?? '' });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ name: data.name.trim() }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.category) {
        form.put(route('social.library.categories.update', { category: props.category.id }), options);
    } else {
        form.post(route('social.library.categories.store'), options);
    }
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        size="sm"
        :title="category ? t('social.library.categories.edit') : t('social.library.categories.add')"
        :submit-label="t('social.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('social.fields.name')" maxlength="80" :error="form.errors.name" required autofocus />
    </SocialFormDialog>
</template>

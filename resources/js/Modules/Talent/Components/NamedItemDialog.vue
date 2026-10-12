<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Checkbox from '@/Core/Components/Checkbox.vue';
import Input from '@/Core/Components/Input.vue';
import TalentFormDialog from './TalentFormDialog.vue';

/**
 * A name with an active flag (and, for pipelines, a default flag): pipelines
 * and rejection reasons. `url` and `method` say where the form goes.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        url: string;
        method: 'post' | 'put';
        name: string;
        isActive: boolean;
        isDefault?: boolean;
        withDefault?: boolean;
        nameLabel: string;
    }>(),
    { isDefault: false, withDefault: false },
);

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', is_active: true, is_default: false });

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ name: props.name, is_active: props.isActive, is_default: props.isDefault });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => (props.withDefault
        ? { name: data.name.trim(), is_active: data.is_active, is_default: data.is_default }
        : { name: data.name.trim(), is_active: data.is_active }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.method === 'put') {
        form.put(props.url, options);
    } else {
        form.post(props.url, options);
    }
}
</script>

<template>
    <TalentFormDialog
        :open="open"
        size="sm"
        :title="title"
        :submit-label="t('talent.common.save')"
        :processing="form.processing"
        :disabled="form.name.trim() === ''"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="nameLabel" maxlength="120" :error="form.errors.name" required autofocus />
        <Checkbox v-model="form.is_active" :label="t('talent.fields.active')" />
        <Checkbox v-if="withDefault" v-model="form.is_default" :label="t('talent.settings.makeDefault')" />
    </TalentFormDialog>
</template>

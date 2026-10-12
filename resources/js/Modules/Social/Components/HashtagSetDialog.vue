<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from '@/Core/Components/Input.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import { route } from '@/Core/lib/route';
import { displayTag, splitTags } from './socialFormat';
import SocialFormDialog from './SocialFormDialog.vue';
import type { HashtagSetRow } from './types';

const MAX_TAGS = 30;

/** Creates a hashtag set or edits `set`. Tags are typed as free text and cleaned by the server. */
const props = defineProps<{ open: boolean; set: HashtagSetRow | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();
const { t } = useI18n();

const form = useForm({ name: '', tags: '' });
const tagCount = computed(() => splitTags(form.tags).length);
const usable = computed(() => form.name.trim() !== '' && tagCount.value >= 1 && tagCount.value <= MAX_TAGS);

watch(() => props.open, (open) => {
    if (open) {
        form.defaults({ name: props.set?.name ?? '', tags: (props.set?.tags ?? []).map(displayTag).join(' ') });
        form.reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit(): void {
    form.transform((data) => ({ name: data.name.trim(), tags: splitTags(data.tags) }));

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.set) {
        form.put(route('social.library.hashtag-sets.update', { set: props.set.id }), options);
    } else {
        form.post(route('social.library.hashtag-sets.store'), options);
    }
}
</script>

<template>
    <SocialFormDialog
        :open="open"
        :title="set ? t('social.library.hashtags.edit') : t('social.library.hashtags.add')"
        :submit-label="t('social.common.save')"
        :processing="form.processing"
        :disabled="!usable"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <Input v-model="form.name" :label="t('social.fields.name')" maxlength="80" :error="form.errors.name" required autofocus />
        <Textarea
            v-model="form.tags"
            :label="t('social.library.hashtags.tags')"
            :hint="t('social.library.hashtags.tagsHint', { max: MAX_TAGS })"
            :rows="4"
            :error="form.errors.tags"
            required
        />
    </SocialFormDialog>
</template>

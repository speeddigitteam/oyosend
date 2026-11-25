<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Modal from '@/Core/Components/Modal.vue';
import type { EditScope } from '../types';

/** Which occurrences of a repeating event a change or a deletion reaches. */
const props = defineProps<{
    mode: 'edit' | 'delete';
    /** "This event" makes no sense when the repetition itself changes. */
    allowThis: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ choose: [scope: EditScope] }>();

const { t } = useI18n();
const scope = ref<EditScope>('this');

watch(open, (value) => {
    if (value) {
        scope.value = props.allowThis ? 'this' : 'following';
    }
});

const choices: EditScope[] = ['this', 'following', 'all'];

function confirm(): void {
    open.value = false;
    emit('choose', scope.value);
}
</script>

<template>
    <Modal v-model:open="open" size="sm" :title="mode === 'edit' ? t('calendar.scope.editHeading') : t('calendar.scope.deleteHeading')">
        <form id="calendar-scope-form" class="grid gap-2" @submit.prevent="confirm">
            <label v-for="choice in choices" v-show="choice !== 'this' || allowThis" :key="choice" class="flex cursor-pointer items-center gap-3 rounded-control px-2 py-2 text-sm text-ink hover:bg-hover">
                <input v-model="scope" type="radio" name="scope" :value="choice" class="accent-[var(--brand)]" />
                {{ t(`calendar.scope.${choice}`) }}
            </label>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('calendar.common.cancel') }}</Button>
            <Button type="submit" form="calendar-scope-form" :variant="mode === 'delete' ? 'danger' : 'primary'">{{ mode === 'delete' ? t('calendar.event.delete') : t('calendar.common.ok') }}</Button>
        </template>
    </Modal>
</template>

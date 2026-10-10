<script setup lang="ts">
import { Search, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Input from '@/Core/Components/Input.vue';
import Spinner from '@/Core/Components/Spinner.vue';

/**
 * The search box of a directory list. The page owns the term and the request
 * (see `useDirectoryFilters`); this is the field, its busy mark and its clear
 * button. Enter emits `submit`, so a search need not wait for the debounce.
 */
defineProps<{
    /** What can be searched for. Shown as the placeholder and read out as the field's name. */
    label: string;
    loading?: boolean;
}>();

const emit = defineEmits<{ submit: []; clear: [] }>();

const term = defineModel<string>({ required: true });

const { t } = useI18n();

const input = ref<InstanceType<typeof Input> | null>(null);

function focus(): void {
    input.value?.focus();
}

function clear(): void {
    term.value = '';
    emit('clear');
    focus();
}

defineExpose({ focus });
</script>

<template>
    <form role="search" class="w-full sm:w-72" @submit.prevent="emit('submit')">
        <Input
            ref="input"
            v-model="term"
            name="search"
            role="searchbox"
            inputmode="search"
            enterkeyhint="search"
            autocomplete="off"
            autocapitalize="none"
            spellcheck="false"
            maxlength="100"
            :aria-label="label"
            :placeholder="label"
        >
            <template #leading><Search class="size-4" aria-hidden="true" /></template>
            <template v-if="loading || term !== ''" #trailing>
                <Spinner v-if="loading" class="mr-2 text-ink-subtle" />
                <Button v-else size="sm" variant="ghost" icon @click="clear">
                    <template #icon><X class="size-4" aria-hidden="true" /></template>
                    {{ t('contacts.list.clearSearch') }}
                </Button>
            </template>
        </Input>
    </form>
</template>

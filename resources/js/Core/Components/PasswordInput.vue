<script setup lang="ts">
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Input from './Input.vue';

defineOptions({ inheritAttrs: false });

defineProps<{
    label?: string;
    hint?: string;
    error?: string;
}>();

const model = defineModel<string>({ default: '' });
const revealed = ref(false);

const { t } = useI18n();
</script>

<template>
    <Input v-model="model" :type="revealed ? 'text' : 'password'" :label="label" :hint="hint" :error="error" v-bind="$attrs">
        <template v-if="$slots.aside" #aside><slot name="aside" /></template>
        <template #trailing>
            <button
                type="button"
                class="flex size-8 items-center justify-center rounded-[0.4375rem] text-ink-subtle transition-colors hover:bg-hover hover:text-ink"
                :aria-label="revealed ? t('auth.hide') : t('auth.show')"
                :aria-pressed="revealed"
                @click="revealed = !revealed"
            >
                <EyeOff v-if="revealed" class="size-4" aria-hidden="true" />
                <Eye v-else class="size-4" aria-hidden="true" />
            </button>
        </template>
    </Input>
</template>

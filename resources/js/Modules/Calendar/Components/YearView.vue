<script setup lang="ts">
import { computed } from 'vue';
import MiniMonth from './MiniMonth.vue';

/** Twelve months of the anchor's year; a day opens in the day view. */
const props = defineProps<{
    year: string;
    today: string;
    weekStartsOn: number;
}>();

const emit = defineEmits<{ pickDate: [date: string] }>();

const months = computed(() => Array.from({ length: 12 }, (_, month) => `${props.year}-${String(month + 1).padStart(2, '0')}-01`));
</script>

<template>
    <div class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
        <div class="mx-auto grid max-w-6xl gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            <MiniMonth v-for="month in months" :key="month" :month="month" :today="today" :week-starts-on="weekStartsOn" :navigable="false" @pick="emit('pickDate', $event)" />
        </div>
    </div>
</template>

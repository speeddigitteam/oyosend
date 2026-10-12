<script setup lang="ts">
import { TabsList, TabsTrigger } from 'reka-ui';

/** The tab strip of an HRM page; use inside reka-ui `TabsRoot`. Arrow keys move between tabs. */
defineProps<{
    label: string;
    items: { value: string; label: string }[];
}>();
</script>

<template>
    <TabsList :aria-label="label" class="mb-3 flex gap-1 overflow-x-auto rounded-control bg-hover p-1">
        <TabsTrigger
            v-for="item in items"
            :key="item.value"
            :value="item.value"
            class="h-8 shrink-0 rounded-control px-3 text-[0.8125rem] font-medium whitespace-nowrap text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-brand data-[state=active]:bg-control data-[state=active]:text-ink"
        >
            {{ item.label }}
        </TabsTrigger>
    </TabsList>
</template>

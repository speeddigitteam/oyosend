<script setup lang="ts">
import { ArrowUpRight, FolderKanban } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
defineProps<{ data: { activeProjects: number; openTasks: number; overdueTasks: number; dueSoonTasks: number } }>();
const { t } = useI18n(); const format = useFormat();
</script>
<template><Card :title="t('projects.widget.title')" class="h-full"><template #actions><Button size="sm" variant="ghost" :href="route('projects.index')">{{ t('projects.widget.view') }}<ArrowUpRight class="size-3.5" aria-hidden="true" /></Button></template><EmptyState v-if="data.activeProjects === 0 && data.openTasks === 0" :title="t('projects.widget.empty')" compact><template #icon><FolderKanban /></template></EmptyState><dl v-else class="grid grid-cols-2 gap-x-5 gap-y-4"><div><dt class="eyebrow">{{ t('projects.widget.active') }}</dt><dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.activeProjects) }}</dd></div><div><dt class="eyebrow">{{ t('projects.widget.openTasks') }}</dt><dd class="figure mt-2 text-3xl text-ink">{{ format.number(data.openTasks) }}</dd></div><div><dt class="eyebrow">{{ t('projects.widget.dueSoon') }}</dt><dd class="figure mt-2 text-2xl text-brand-text">{{ format.number(data.dueSoonTasks) }}</dd></div><div><dt class="eyebrow">{{ t('projects.widget.overdue') }}</dt><dd class="figure mt-2 text-2xl" :class="data.overdueTasks ? 'text-danger' : 'text-ink'">{{ format.number(data.overdueTasks) }}</dd></div></dl></Card></template>

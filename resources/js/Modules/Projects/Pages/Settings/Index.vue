<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LayoutTemplate, Plus, Tags, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import EmptyState from '@/Core/Components/EmptyState.vue';
import Input from '@/Core/Components/Input.vue';
import Modal from '@/Core/Components/Modal.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Select from '@/Core/Components/Select.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import ProjectsNav from '../../Components/ProjectsNav.vue';
import type { Option, TaskPriority } from '../../Components/types';
defineOptions({ layout: TenantLayout });
interface Category extends Option { colour?: string | null; is_active: boolean; position: number }
interface Template extends Option { description?: string | null; category?: Option | null; taskCount: number; active: boolean }
interface Blueprint { title: string; description: string; priority: TaskPriority; estimated_minutes: string }
type BlueprintErrorKey = 'task_blueprints' | `task_blueprints.${number}` | `task_blueprints.${number}.${keyof Blueprint}`;
const props = defineProps<{ categories: Category[]; templates: Template[] }>();
const { t } = useI18n(); const categoryOpen = ref(false); const templateOpen = ref(false);
const category = useForm({ name: '', colour: '#4f46e5', position: '0' });
const template = useForm<{ name: string; description: string; category_id: string; task_blueprints: Blueprint[] }>({ name: '', description: '', category_id: '', task_blueprints: [] });
const priorities: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];
const blueprintKeys = ref<number[]>([]);
let nextBlueprintKey = 0;
const canAddBlueprint = computed(() => template.task_blueprints.length < 100);
watch(categoryOpen, (open) => { if (open) { category.reset(); category.clearErrors(); } });
watch(templateOpen, (open) => { if (open) { template.reset(); template.clearErrors(); blueprintKeys.value = []; } });
function addBlueprint(): void {
    if (!canAddBlueprint.value) return;
    template.task_blueprints.push({ title: '', description: '', priority: 'normal', estimated_minutes: '' });
    blueprintKeys.value.push(nextBlueprintKey++);
}
function removeBlueprint(index: number): void {
    template.task_blueprints.splice(index, 1);
    blueprintKeys.value.splice(index, 1);
    const blueprintErrors = Object.keys(template.errors).filter((key) => key === 'task_blueprints' || key.startsWith('task_blueprints.')) as BlueprintErrorKey[];
    template.clearErrors(...blueprintErrors);
}
function saveCategory(): void { category.post(route('projects.settings.categories.store'), { preserveScroll: true, onSuccess: () => { categoryOpen.value = false; category.reset(); } }); }
function saveTemplate(): void { template.post(route('projects.settings.templates.store'), { preserveScroll: true, onSuccess: () => { templateOpen.value = false; template.reset(); } }); }
</script>
<template>
    <PageHeader :eyebrow="t('projects.eyebrow')" :title="t('projects.settings.title')" :description="t('projects.settings.description')" /><ProjectsNav current="settings" />
    <div class="grid gap-3 xl:grid-cols-2"><Card :title="t('projects.settings.categories')" flush><template #actions><Button size="sm" variant="primary" @click="categoryOpen = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.settings.addCategory') }}</Button></template><EmptyState v-if="categories.length === 0" :title="t('projects.settings.emptyCategories')" compact><template #icon><Tags /></template></EmptyState><ul v-else class="divide-y divide-line"><li v-for="item in categories" :key="item.id" class="flex items-center justify-between gap-3 px-5 py-3"><div class="flex items-center gap-3"><span class="size-3 rounded-full border border-line" :style="{ backgroundColor: item.colour ?? 'var(--brand)' }" aria-hidden="true" /><span class="text-sm font-medium text-ink">{{ item.name }}</span></div><span class="text-xs text-ink-subtle">{{ t(item.is_active ? 'projects.status.active' : 'projects.status.cancelled') }}</span></li></ul></Card><Card :title="t('projects.settings.templates')" flush><template #actions><Button size="sm" variant="primary" @click="templateOpen = true"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.settings.addTemplate') }}</Button></template><EmptyState v-if="templates.length === 0" :title="t('projects.settings.emptyTemplates')" compact><template #icon><LayoutTemplate /></template></EmptyState><ul v-else class="divide-y divide-line"><li v-for="item in templates" :key="item.id" class="px-5 py-3"><div class="flex justify-between gap-3"><p class="text-sm font-medium text-ink">{{ item.name }}</p><span class="font-mono text-xs text-ink-subtle">{{ t('projects.settings.taskCount', { count: item.taskCount }) }}</span></div><p v-if="item.description" class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ item.description }}</p><p v-if="item.category" class="mt-1 text-xs text-ink-subtle">{{ item.category.name }}</p></li></ul></Card></div>
    <Modal v-model:open="categoryOpen" :title="t('projects.settings.addCategory')" :persistent="category.processing"><form id="project-category-form" class="grid gap-4" @submit.prevent="saveCategory"><Input v-model="category.name" :label="t('projects.fields.name')" :error="category.errors.name" required maxlength="100" /><Input v-model="category.colour" type="color" :label="t('projects.settings.colour')" :error="category.errors.colour" /><Input v-model="category.position" type="number" min="0" max="65535" :label="t('projects.settings.position')" :error="category.errors.position" /></form><template #footer><Button variant="ghost" :disabled="category.processing" @click="categoryOpen = false">{{ t('projects.common.cancel') }}</Button><Button form="project-category-form" type="submit" variant="primary" :loading="category.processing">{{ t('projects.common.save') }}</Button></template></Modal>
    <Modal v-model:open="templateOpen" size="lg" :title="t('projects.settings.addTemplate')" :persistent="template.processing"><form id="project-template-form" class="grid gap-4" @submit.prevent="saveTemplate"><Input v-model="template.name" :label="t('projects.fields.name')" :error="template.errors.name" required maxlength="160" /><Select v-model="template.category_id" :options="[{ value: '', label: t('projects.common.notSet') }, ...categories.map((item) => ({ value: item.id, label: item.name }))]" :label="t('projects.fields.category')" :error="template.errors.category_id" /><Textarea v-model="template.description" :label="t('projects.fields.description')" :error="template.errors.description" optional maxlength="10000" /><fieldset class="grid gap-3"><legend class="text-sm font-semibold text-ink">{{ t('projects.settings.blueprints') }}</legend><div v-for="(task, index) in template.task_blueprints" :key="blueprintKeys[index]" class="glass-well grid gap-3 rounded-control p-3 sm:grid-cols-2"><Input v-model="task.title" class="sm:col-span-2" :label="t('projects.fields.title')" :error="template.errors[`task_blueprints.${index}.title`]" required maxlength="240" /><Select v-model="task.priority" :options="priorities.map((value) => ({ value, label: t(`projects.status.${value}`) }))" :label="t('projects.fields.priority')" :error="template.errors[`task_blueprints.${index}.priority`]" /><Input v-model="task.estimated_minutes" type="number" min="0" max="10000000" :label="t('projects.fields.estimateMinutes')" :error="template.errors[`task_blueprints.${index}.estimated_minutes`]" /><Button icon size="sm" variant="ghost" class="justify-self-end sm:col-span-2" :aria-label="t('projects.settings.removeTaskFor', { number: index + 1 })" @click="removeBlueprint(index)"><template #icon><Trash2 class="size-4" aria-hidden="true" /></template></Button></div><p v-if="template.errors.task_blueprints" class="text-sm text-danger" role="alert">{{ template.errors.task_blueprints }}</p><Button v-if="canAddBlueprint" size="sm" variant="ghost" class="justify-self-start" @click="addBlueprint"><template #icon><Plus class="size-4" aria-hidden="true" /></template>{{ t('projects.settings.addTask') }}</Button></fieldset></form><template #footer><Button variant="ghost" :disabled="template.processing" @click="templateOpen = false">{{ t('projects.common.cancel') }}</Button><Button form="project-template-form" type="submit" variant="primary" :loading="template.processing">{{ t('projects.common.save') }}</Button></template></Modal>
</template>

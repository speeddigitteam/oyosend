<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import CommunicationNav from '../../Components/CommunicationNav.vue';
import { useCommunicationLabels } from '../../Components/useCommunicationLabels';

defineOptions({ layout: TenantLayout });

interface PreferenceRow {
    category: string;
    in_app: boolean;
    email: boolean;
}

const props = defineProps<{
    preferences: PreferenceRow[];
    emailAvailable: boolean;
}>();

const { t } = useI18n();
const { label } = useCommunicationLabels();

const form = useForm({ preferences: props.preferences.map((row) => ({ ...row })) });

function save(): void {
    form.put(route('communication.preferences.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('communication.eyebrow')" :title="t('communication.preferences.title')" :description="t('communication.preferences.description')">
        <template #actions>
            <Button variant="secondary" :href="route('communication.notifications.index')">
                <template #icon><ArrowLeft class="size-4" aria-hidden="true" /></template>
                {{ t('communication.preferences.back') }}
            </Button>
        </template>
    </PageHeader>
    <CommunicationNav current="notifications" />

    <!-- Full width: the choices on the left, the notes about them beside it on wide screens, one save below both. -->
    <form class="grid gap-3 lg:grid-cols-3" @submit.prevent="save">
        <Card flush class="lg:col-span-2">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ t('communication.preferences.title') }}</caption>
                <thead>
                    <tr class="border-b border-line text-left">
                        <th scope="col" class="eyebrow px-5 py-3 font-normal">{{ t('communication.preferences.category') }}</th>
                        <th scope="col" class="eyebrow px-3 py-3 text-center font-normal">{{ t('communication.preferences.inApp') }}</th>
                        <th scope="col" class="eyebrow px-5 py-3 text-center font-normal">{{ t('communication.preferences.email') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in form.preferences" :key="row.category">
                        <th scope="row" class="px-5 py-3 text-left font-normal text-ink">{{ label('category', row.category) }}</th>
                        <td class="px-3 py-3">
                            <div class="flex justify-center">
                                <Checkbox v-model="row.in_app">
                                    <span class="sr-only">
                                        {{ t('communication.preferences.toggle', { channel: t('communication.preferences.inApp'), category: label('category', row.category) }) }}
                                    </span>
                                </Checkbox>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex justify-center">
                                <Checkbox v-model="row.email">
                                    <span class="sr-only">
                                        {{ t('communication.preferences.toggle', { channel: t('communication.preferences.email'), category: label('category', row.category) }) }}
                                    </span>
                                </Checkbox>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <div class="grid content-start gap-3">
            <p v-if="!emailAvailable" class="rounded-card bg-warn-soft px-4 py-3 text-sm text-warn" role="status">
                {{ t('communication.preferences.emailUnavailable') }}
            </p>
            <p class="text-sm text-ink-subtle">{{ t('communication.preferences.critical') }} {{ t('communication.preferences.saved') }}</p>
        </div>

        <div class="flex justify-end lg:col-span-3">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('communication.common.save') }}</Button>
        </div>
    </form>
</template>

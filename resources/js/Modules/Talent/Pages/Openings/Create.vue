<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import OpeningForm from '../../Components/OpeningForm.vue';
import type { OpeningForm as OpeningFormOptions } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{ form: OpeningFormOptions }>();

const { t } = useI18n();

function cancel(): void {
    router.visit(route('talent.openings.index'));
}
</script>

<template>
    <PageHeader :eyebrow="t('talent.eyebrow')" :title="t('talent.openings.add')" :description="t('talent.openings.createHint')" />
    <Card>
        <OpeningForm :options="form" :opening="null" @cancel="cancel" />
    </Card>
</template>

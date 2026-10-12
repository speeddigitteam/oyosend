<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Card from '@/Core/Components/Card.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import EmployeeForm from '../../Components/EmployeeForm.vue';
import type { EmployeeFormOptions } from '../../Components/types';

defineOptions({ layout: TenantLayout });

defineProps<{
    form: EmployeeFormOptions;
    defaults: { joinedOn: string; status: string; employmentType: string };
}>();

const { t } = useI18n();

function cancel(): void {
    router.visit(route('hrm.employees.index'));
}
</script>

<template>
    <PageHeader :eyebrow="t('hrm.eyebrow')" :title="t('hrm.employees.addTitle')" :description="t('hrm.employees.addDescription')" />

    <Card>
        <EmployeeForm :options="form" :defaults="defaults" can-edit-employment @cancel="cancel" />
    </Card>
</template>

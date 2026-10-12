<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import type { LeaveSession } from './types';

/** "12 Oct - 14 Oct 2026", with the half day marked where the request starts or ends on one. */
const props = defineProps<{
    startsOn: string;
    endsOn: string;
    startSession: LeaveSession;
    endSession: LeaveSession;
}>();

const { t } = useI18n();
const format = useFormat();

const text = computed(() => {
    if (props.startsOn === props.endsOn) {
        const day = format.date(props.startsOn);

        return props.startSession === 'full' ? day : `${day} (${t(`hrm.session.${props.startSession}`)})`;
    }

    const start = props.startSession === 'full' ? format.date(props.startsOn) : `${format.date(props.startsOn)} (${t(`hrm.session.${props.startSession}`)})`;
    const end = props.endSession === 'full' ? format.date(props.endsOn) : `${format.date(props.endsOn)} (${t(`hrm.session.${props.endSession}`)})`;

    return `${start} - ${end}`;
});
</script>

<template>
    <span class="tabular">{{ text }}</span>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LogIn, LogOut } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';
import HrmStatusBadge from './HrmStatusBadge.vue';
import { useHrmFormat } from './hrmFormat';
import type { ClockState } from './types';

/**
 * Clock in and out. The server clock is the only authority: the request carries
 * no time, and the "so far" figure here is only a display that ticks locally.
 */
const props = defineProps<{ clock: ClockState; canClock: boolean }>();

const { t } = useI18n();
const format = useFormat();
const hrm = useHrmFormat();

const form = useForm({ note: '' });
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 15_000); });
onBeforeUnmount(() => { if (timer !== undefined) clearInterval(timer); });

const elapsedMinutes = computed(() => {
    if (!props.clock.since) {
        return 0;
    }

    return Math.max(0, Math.floor((now.value - new Date(props.clock.since).getTime()) / 60_000));
});

const firstError = computed(() => Object.values(form.errors)[0] as string | undefined);

function submit(): void {
    const name = props.clock.clockedIn ? 'hrm.attendance.clock-out' : 'hrm.attendance.clock-in';

    form.post(route(name), { preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <Card :title="t('hrm.clock.title')" data-testid="clock-card">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0" aria-live="polite">
                <template v-if="clock.clockedIn && clock.since">
                    <p class="eyebrow">{{ t('hrm.clock.in') }}</p>
                    <p class="figure mt-1 text-2xl text-ink" data-testid="clock-since">
                        {{ t('hrm.clock.since', { time: format.date(clock.since, 'time') }) }}
                    </p>
                    <p class="mt-1 text-sm text-ink-muted" data-testid="clock-elapsed">{{ t('hrm.clock.soFar', { duration: hrm.duration(elapsedMinutes) }) }}</p>
                </template>
                <template v-else>
                    <p class="eyebrow">{{ t('hrm.clock.out') }}</p>
                    <p class="mt-1 text-lg text-ink" data-testid="clock-idle">
                        {{ clock.holiday ? t('hrm.clock.holiday', { name: clock.holiday }) : clock.isWorkingDay ? t('hrm.clock.workingDay') : t('hrm.clock.dayOff') }}
                    </p>
                    <p v-if="clock.day && clock.day.workedMinutes > 0" class="mt-1 text-sm text-ink-muted">
                        {{ t('hrm.clock.workedToday', { duration: hrm.duration(clock.day.workedMinutes) }) }}
                    </p>
                </template>
                <p v-if="clock.shift" class="mt-2 text-xs text-ink-subtle">
                    {{ t('hrm.clock.shift', { name: clock.shift.name, start: hrm.clock(clock.shift.startsAt), end: hrm.clock(clock.shift.endsAt) }) }}
                </p>
            </div>
            <div v-if="clock.day" class="shrink-0">
                <HrmStatusBadge kind="day" :value="clock.day.status" />
            </div>
        </div>

        <form v-if="canClock" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <div v-if="!clock.clockedIn" class="min-w-0 flex-1 basis-48">
                <Input v-model="form.note" :label="t('hrm.clock.note')" optional maxlength="300" :error="form.errors.note" />
            </div>
            <Button type="submit" :variant="clock.clockedIn ? 'secondary' : 'primary'" :loading="form.processing">
                <template #icon>
                    <LogOut v-if="clock.clockedIn" class="size-4" aria-hidden="true" />
                    <LogIn v-else class="size-4" aria-hidden="true" />
                </template>
                {{ clock.clockedIn ? t('hrm.clock.clockOut') : t('hrm.clock.clockIn') }}
            </Button>
        </form>
        <p v-if="firstError && !form.errors.note" class="mt-2 text-sm text-danger" role="alert">{{ firstError }}</p>
        <p v-if="!canClock" class="mt-3 text-sm text-ink-muted">{{ t('hrm.clock.unavailable') }}</p>
    </Card>
</template>

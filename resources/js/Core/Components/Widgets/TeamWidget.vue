<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import Avatar from '@/Core/Components/Avatar.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { route } from '@/Core/lib/route';

interface TeamData {
    members: number;
    suspended: number;
    pendingInvitations: number;
    roles: number;
    joinedThisMonth: number;
    recent: Array<{ id: string; name: string; initials: string; joinedAt: string | null }>;
}

defineProps<{ data: TeamData }>();

const { t } = useI18n();
const format = useFormat();
</script>

<template>
    <Card :title="t('dashboard.team.title')" class="h-full">
        <template #actions>
            <Button size="sm" variant="ghost" :href="route('core.members.index')">
                {{ t('dashboard.team.manage') }}
                <ArrowUpRight class="size-3.5" aria-hidden="true" />
            </Button>
        </template>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-4">
            <div class="flex flex-col-reverse justify-end gap-2">
                <dt class="eyebrow">
                    {{ t('dashboard.team.members') }}
                    <span v-if="data.suspended > 0" class="mt-1 block font-sans text-[0.8125rem] tracking-normal normal-case">
                        {{ t('dashboard.team.suspended', { count: format.number(data.suspended) }) }}
                    </span>
                </dt>
                <dd class="figure text-[2.5rem] text-ink">{{ format.number(data.members) }}</dd>
            </div>
            <div class="flex flex-col-reverse justify-end gap-2">
                <dt class="eyebrow">{{ t('dashboard.team.pendingInvitations') }}</dt>
                <dd class="figure text-[2.5rem]" :class="data.pendingInvitations > 0 ? 'text-accent' : 'text-ink'">
                    {{ format.number(data.pendingInvitations) }}
                </dd>
            </div>
            <div class="flex flex-col-reverse justify-end gap-2">
                <dt class="eyebrow">{{ t('dashboard.team.roles') }}</dt>
                <dd class="figure text-[2.5rem] text-ink">{{ format.number(data.roles) }}</dd>
            </div>
            <div class="flex flex-col-reverse justify-end gap-2">
                <dt class="eyebrow">{{ t('dashboard.team.joinedThisMonth') }}</dt>
                <dd class="figure text-[2.5rem] text-ink">{{ format.number(data.joinedThisMonth) }}</dd>
            </div>
        </dl>

        <div v-if="data.recent.length" class="mt-6 border-t border-line pt-4">
            <p class="eyebrow mb-3">{{ t('dashboard.team.recent') }}</p>
            <ul class="flex flex-wrap gap-x-5 gap-y-3">
                <li v-for="member in data.recent" :key="member.id" class="flex min-w-0 items-center gap-2.5">
                    <Avatar :initials="member.initials" :name="member.name" size="sm" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm leading-tight text-ink">{{ member.name }}</span>
                        <span v-if="member.joinedAt" class="block text-xs leading-tight text-ink-subtle">
                            {{ t('dashboard.team.joined', { when: format.relative(member.joinedAt) }) }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>
    </Card>
</template>

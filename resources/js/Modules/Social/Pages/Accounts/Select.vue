<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Checkbox from '@/Core/Components/Checkbox.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { route } from '@/Core/lib/route';
import { safeUrl } from '../../Components/socialFormat';

defineOptions({ layout: TenantLayout });

interface Profile {
    externalId: string;
    name: string;
    handle: string | null;
    avatarUrl: string | null;
    type: string | null;
    alreadyConnected: boolean;
}

const props = defineProps<{
    selection: string;
    platform: string;
    platformLabel: string;
    profiles: Profile[];
    limit: { limit: number | null; used: number };
}>();

const { t } = useI18n();
const format = useFormat();

const form = useForm<{ external_ids: string[] }>({ external_ids: [] });

const remaining = computed(() => (props.limit.limit === null ? null : Math.max(0, props.limit.limit - props.limit.used)));
const overLimit = computed(() => remaining.value !== null && form.external_ids.length > remaining.value);
const idErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => key === 'external_ids' || key.startsWith('external_ids.'))
    .map(([, message]) => message));

function toggle(id: string, on: boolean): void {
    form.external_ids = on ? [...form.external_ids.filter((item) => item !== id), id] : form.external_ids.filter((item) => item !== id);
}

function avatar(profile: Profile): string | null {
    const url = safeUrl(profile.avatarUrl);

    return url !== null && url.startsWith('https://') ? url : null;
}

function submit(): void {
    form.post(route('social.accounts.select.store', { selection: props.selection }));
}
</script>

<template>
    <PageHeader
        :eyebrow="t('social.eyebrow')"
        :title="t('social.select.title', { platform: platformLabel })"
        :description="t('social.select.description')"
    />

    <form novalidate @submit.prevent="submit">
        <Card :description="remaining === null ? undefined : t('social.select.seats', { count: format.number(remaining) })">
            <ul class="grid gap-3">
                <li v-for="profile in profiles" :key="profile.externalId" class="flex items-center gap-3 rounded-control border border-line p-3">
                    <img
                        v-if="avatar(profile)"
                        :src="avatar(profile)!"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        class="size-9 shrink-0 rounded-full bg-hover object-cover"
                    />
                    <Checkbox
                        class="min-w-0 flex-1"
                        :model-value="form.external_ids.includes(profile.externalId)"
                        :disabled="profile.alreadyConnected"
                        :label="profile.name"
                        :description="profile.alreadyConnected
                            ? t('social.select.alreadyConnected')
                            : [profile.handle, profile.type].filter((item) => item).join(' · ') || undefined"
                        @update:model-value="toggle(profile.externalId, $event)"
                    />
                </li>
            </ul>

            <p v-if="overLimit" class="mt-3 text-[0.8125rem] text-warn" role="status" data-testid="over-limit">{{ t('social.select.overLimit') }}</p>
            <p v-for="message in idErrors" :key="message" class="mt-3 text-[0.8125rem] text-danger" role="alert">{{ message }}</p>

            <template #footer>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button variant="ghost" :href="route('social.accounts.index')">{{ t('social.common.cancel') }}</Button>
                    <Button type="submit" variant="primary" :loading="form.processing" :disabled="form.external_ids.length === 0 || overLimit">
                        {{ t('social.select.submit') }}
                    </Button>
                </div>
            </template>
        </Card>
    </form>
</template>

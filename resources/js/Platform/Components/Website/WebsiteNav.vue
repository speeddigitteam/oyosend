<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import { route } from '@/Core/lib/route';
import { useAbilities } from '../abilities';

defineProps<{ current: 'messages' | 'company' | 'texts' | 'blog' }>();

const { t } = useI18n();
const { allows } = useAbilities();

// Reading messages is support work; changing the website is for those who manage it.
const items = computed(() =>
    [
        { value: 'messages', name: 'platform.website.messages.index', ability: 'platform.website.view' },
        { value: 'company', name: 'platform.website.company.edit', ability: 'platform.website.manage' },
        { value: 'texts', name: 'platform.website.texts.index', ability: 'platform.website.manage' },
        { value: 'blog', name: 'platform.website.blog.index', ability: 'platform.website.manage' },
    ].filter((item) => allows(item.ability)),
);
</script>

<template>
    <nav :aria-label="t('platformWebsite.nav.label')" class="mb-4 overflow-x-auto pb-1">
        <div class="flex min-w-max gap-1 rounded-control bg-hover p-1">
            <Button
                v-for="item in items"
                :key="item.value"
                size="sm"
                :variant="item.value === current ? 'secondary' : 'ghost'"
                :href="route(item.name)"
                :aria-current="item.value === current ? 'page' : undefined"
            >
                {{ t(`platformWebsite.nav.${item.value}`) }}
            </Button>
        </div>
    </nav>
</template>

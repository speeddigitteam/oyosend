<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, Languages } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Dropdown from '@/Core/Components/Dropdown.vue';
import DropdownItem from '@/Core/Components/DropdownItem.vue';
import DropdownLabel from '@/Core/Components/DropdownLabel.vue';
import { route } from '@/Core/lib/route';

/**
 * Interface language. Saved to the account when signed in to a workspace,
 * otherwise remembered in a cookie.
 */
const page = usePage();
const { t } = useI18n();

const locales = computed(() => Object.entries(page.props.app.locales).map(([code, name]) => ({ code, name })));

// The Super Admin panel is English-only for now and has no locale route.
const endpoint = computed(() => {
    switch (page.props.app.area) {
        case 'tenant':
            return route('core.locale.update');
        case 'central':
            return route('central.locale.update');
        default:
            return null;
    }
});

function choose(locale: string): void {
    if (endpoint.value === null || locale === page.props.app.locale) {
        return;
    }

    router.post(endpoint.value, { locale }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Dropdown v-if="endpoint && locales.length > 1" width="w-44">
        <template #trigger>
            <button
                type="button"
                class="flex h-9 shrink-0 items-center gap-1.5 rounded-control px-2 text-[0.8125rem] font-medium text-ink-muted transition-colors hover:bg-hover hover:text-ink"
                :aria-label="t('common.language.label')"
            >
                <Languages class="size-[1.125rem]" aria-hidden="true" />
                <span class="uppercase">{{ page.props.app.locale }}</span>
            </button>
        </template>

        <DropdownLabel>{{ t('common.language.label') }}</DropdownLabel>
        <DropdownItem
            v-for="locale in locales"
            :key="locale.code"
            :active="locale.code === page.props.app.locale"
            :lang="locale.code"
            @select="choose(locale.code)"
        >
            {{ locale.name }}
            <template v-if="locale.code === page.props.app.locale" #trailing>
                <Check class="size-4 text-brand-text" aria-hidden="true" />
            </template>
        </DropdownItem>
    </Dropdown>
</template>

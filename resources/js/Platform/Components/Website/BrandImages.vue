<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Logo from '@/Core/Components/Logo.vue';
import { route } from '@/Core/lib/route';

type Asset = 'logo' | 'logo_dark' | 'favicon';

/**
 * The logo, the logo for dark backgrounds and the favicon: each previewed on the background it will sit on, uploaded
 * on its own, and removable to bring back the built-in image. The server checks every file again.
 */
const props = defineProps<{
    /** The uploaded image of each, or null where the built-in one is used. */
    brand: Record<Asset, string | null>;
}>();

const { t } = useI18n();
const page = usePage();

const ACCEPT: Record<Asset, string> = {
    logo: 'image/png,image/jpeg,image/webp',
    logo_dark: 'image/png,image/jpeg,image/webp',
    favicon: 'image/png,image/x-icon,image/vnd.microsoft.icon,.ico',
};

const ASSETS: Asset[] = ['logo', 'logo_dark', 'favicon'];
/** The favicon the platform ships (public/favicon.svg). Bound, so no build step treats it as a module. */
const BUILT_IN_FAVICON = '/favicon.svg';
const KEYS: Record<Asset, string> = { logo: 'logo', logo_dark: 'logoDark', favicon: 'favicon' };

const inputs = ref<Partial<Record<Asset, HTMLInputElement | null>>>({});
const busy = ref<Asset | null>(null);
const lastTried = ref<Asset | null>(null);

const error = computed(() => (page.props.errors as Record<string, string> | undefined)?.file);

function choose(asset: Asset): void {
    inputs.value[asset]?.click();
}

function upload(asset: Asset, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    busy.value = asset;
    lastTried.value = asset;
    router.post(route('platform.website.brand.store', { asset }), { file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
            input.value = '';
        },
    });
}

function remove(asset: Asset): void {
    busy.value = asset;
    lastTried.value = null;
    router.delete(route('platform.website.brand.destroy', { asset }), { preserveScroll: true, onFinish: () => (busy.value = null) });
}
</script>

<template>
    <Card :title="t('platformWebsite.company.brand.title')" :description="t('platformWebsite.company.brand.description')">
        <div class="@container">
            <ul class="grid gap-3 @3xl:grid-cols-3" data-testid="brand">
                <li v-for="asset in ASSETS" :key="asset" class="flex flex-col rounded-card border border-line p-4" :data-asset="asset">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                        <h3 class="text-sm font-semibold text-ink">{{ t(`platformWebsite.company.brand.${KEYS[asset]}`) }}</h3>
                        <Badge :tone="props.brand[asset] ? 'brand' : 'neutral'">
                            {{ props.brand[asset] ? t('platformWebsite.company.brand.uploaded') : t('platformWebsite.company.brand.builtIn') }}
                        </Badge>
                    </div>

                    <!-- Previewed where it will sit: the dark-background logo on the dark theme's own canvas. -->
                    <div
                        :data-theme="asset === 'logo_dark' ? 'dark' : undefined"
                        :class="[
                            'mt-3 grid h-24 place-items-center rounded-control border border-line px-4',
                            asset === 'logo_dark' ? 'bg-canvas' : 'glass-well',
                        ]"
                    >
                        <img
                            v-if="props.brand[asset]"
                            :src="props.brand[asset]!"
                            alt=""
                            :class="asset === 'favicon' ? 'size-12 object-contain' : 'max-h-12 max-w-full object-contain'"
                        />
                        <img v-else-if="asset === 'favicon'" :src="BUILT_IN_FAVICON" alt="" class="size-12" />
                        <img v-else-if="asset === 'logo_dark' && props.brand.logo" :src="props.brand.logo" alt="" class="max-h-12 max-w-full object-contain" />
                        <Logo v-else />
                    </div>

                    <p class="mt-3 text-[0.8125rem] text-ink-muted">{{ t(`platformWebsite.company.brand.${KEYS[asset]}Hint`) }}</p>
                    <p v-if="error && lastTried === asset" class="mt-2 text-[0.8125rem] text-danger" role="alert">{{ error }}</p>

                    <input
                        :ref="(element) => (inputs[asset] = element as HTMLInputElement | null)"
                        type="file"
                        class="sr-only"
                        tabindex="-1"
                        :accept="ACCEPT[asset]"
                        :aria-label="t(`platformWebsite.company.brand.${KEYS[asset]}`)"
                        @change="upload(asset, $event)"
                    />

                    <div class="mt-auto flex flex-wrap justify-end gap-2 pt-4">
                        <Button v-if="props.brand[asset]" variant="ghost" size="sm" :disabled="busy !== null" @click="remove(asset)">
                            {{ t('platformWebsite.company.brand.remove') }}
                        </Button>
                        <Button size="sm" :loading="busy === asset" :disabled="busy !== null && busy !== asset" @click="choose(asset)">
                            {{ props.brand[asset] ? t('platformWebsite.company.brand.replace') : t('platformWebsite.company.brand.upload') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </Card>
</template>

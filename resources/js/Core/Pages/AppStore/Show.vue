<script setup lang="ts">
import { Check, ChevronLeft, KeyRound, Puzzle, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppActionPanel from '@/Core/Components/AppStore/AppActionPanel.vue';
import AppSettingsPanel from '@/Core/Components/AppStore/AppSettingsPanel.vue';
import AppStatusBadge from '@/Core/Components/AppStore/AppStatusBadge.vue';
import { useAppCatalog } from '@/Core/Components/AppStore/catalog';
import { useAppStoreTerms } from '@/Core/Components/AppStore/terms';
import type { StoreApp } from '@/Core/Components/AppStore/types';
import Badge from '@/Core/Components/Badge.vue';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Icon from '@/Core/Components/Icon.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import { useFormat } from '@/Core/composables/useFormat';
import TenantLayout from '@/Core/Layouts/TenantLayout.vue';
import { permissionDescription, permissionLabel } from '@/Core/lib/labels';
import { route } from '@/Core/lib/route';

defineOptions({ layout: TenantLayout });

const props = defineProps<{ app: StoreApp; dependencyNames: Record<string, string> }>();

const { t } = useI18n();
const format = useFormat();
const terms = useAppStoreTerms();
const catalog = useAppCatalog();
const appName = computed(() => catalog.name(props.app));
const features = computed(() => catalog.features(props.app));
const releaseNotes = computed(() => catalog.releaseNotes(props.app));
</script>

<template>
    <PageHeader :eyebrow="t('appStore.show.eyebrow')" :title="appName" :description="catalog.description(app)">
        <template #actions>
            <Button :href="route('core.apps.index')" variant="ghost">
                <template #icon><ChevronLeft class="size-4" aria-hidden="true" /></template>
                {{ t('appStore.actions.backToStore') }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="min-w-0 space-y-5">
            <Card>
                <div class="flex flex-wrap items-start gap-4">
                    <div class="glass-well flex size-14 shrink-0 items-center justify-center rounded-[1rem] text-brand-text">
                        <Icon :name="app.icon ?? 'package'" class="size-7" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge tone="neutral">{{ terms.category(app.category) }}</Badge>
                            <Badge v-if="app.featured" tone="brand">{{ t('appStore.badges.featured') }}</Badge>
                            <Badge v-if="app.popular" tone="accent">{{ t('appStore.badges.popular') }}</Badge>
                            <AppStatusBadge v-if="app.installation" :status="app.installation.status" />
                        </div>
                        <dl class="mt-4 grid gap-x-6 gap-y-2 text-[0.8125rem] sm:grid-cols-[auto_1fr]">
                            <dt class="text-ink-subtle">{{ t('appStore.show.version') }}</dt>
                            <dd class="font-mono text-ink">{{ app.version }}</dd>
                            <template v-if="app.compatibility">
                                <dt class="text-ink-subtle">{{ t('appStore.show.compatibility') }}</dt>
                                <dd class="text-ink">{{ app.compatibility }}</dd>
                            </template>
                            <template v-if="app.installation?.installedAt">
                                <dt class="text-ink-subtle">{{ t('appStore.show.installed') }}</dt>
                                <dd class="text-ink">{{ format.date(app.installation.installedAt) }}</dd>
                            </template>
                        </dl>
                    </div>
                </div>
            </Card>

            <Card v-if="app.features.length" :title="t('appStore.show.features.title')" :description="t('appStore.show.features.body')">
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li v-for="feature in features" :key="feature" class="flex items-start gap-2 text-[0.8125rem] text-ink-muted">
                        <Check class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />
                        <span>{{ feature }}</span>
                    </li>
                </ul>
            </Card>

            <Card v-if="app.screenshots.length" :title="t('appStore.show.screenshots')">
                <div class="grid gap-3 sm:grid-cols-2">
                    <img
                        v-for="(screenshot, index) in app.screenshots"
                        :key="screenshot"
                        :src="screenshot"
                        :alt="t('appStore.show.screenshotAlt', { app: appName, number: format.number(index + 1) })"
                        loading="lazy"
                        decoding="async"
                        class="aspect-video w-full rounded-control border border-line bg-hover object-cover"
                    />
                </div>
            </Card>

            <Card v-if="releaseNotes" :title="t('appStore.show.releaseNotes', { version: app.version })">
                <p class="whitespace-pre-line text-[0.8125rem] text-ink-muted">{{ releaseNotes }}</p>
            </Card>

            <AppSettingsPanel
                v-if="app.settings.length && app.installation && app.installation.status !== 'uninstalled'"
                :app-key="app.key"
                :settings="app.settings"
            />
        </div>

        <aside class="space-y-5 xl:sticky xl:top-20">
            <AppActionPanel :app="app" />

            <Card v-if="Object.keys(dependencyNames).length" :title="t('appStore.show.dependencies.title')">
                <ul class="space-y-2">
                    <li v-for="(name, key) in dependencyNames" :key="key" class="flex items-center gap-2 text-[0.8125rem] text-ink-muted">
                        <Puzzle class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                        <span>{{ name }}</span>
                    </li>
                </ul>
                <template #footer>
                    <p class="text-xs text-ink-subtle">{{ t('appStore.show.dependencies.body') }}</p>
                </template>
            </Card>

            <Card v-if="app.permissions.length" :title="t('appStore.show.permissions.title')">
                <ul class="space-y-3">
                    <li v-for="permission in app.permissions" :key="permission" class="flex items-start gap-2">
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                        <div>
                            <p class="text-[0.8125rem] font-medium text-ink">{{ permissionLabel(permission) }}</p>
                            <p v-if="permissionDescription(permission)" class="mt-0.5 text-xs text-ink-subtle">
                                {{ permissionDescription(permission) }}
                            </p>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card v-if="app.licence" :title="t('appStore.show.licence.title')">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[0.8125rem]">
                    <dt class="text-ink-subtle">{{ t('appStore.show.licence.source') }}</dt>
                    <dd class="text-ink">{{ t(`appStore.terms.licenceSource.${app.licence.source}`) }}</dd>
                    <dt class="text-ink-subtle">{{ t('appStore.show.licence.id') }}</dt>
                    <dd class="truncate font-mono text-xs text-ink" :title="app.licence.id">{{ app.licence.id }}</dd>
                    <template v-if="app.licence.validUntil">
                        <dt class="text-ink-subtle">{{ t('appStore.show.licence.validUntil') }}</dt>
                        <dd class="text-ink">{{ format.date(app.licence.validUntil) }}</dd>
                    </template>
                </dl>
                <template #header>
                    <div class="flex items-center gap-2">
                        <KeyRound class="size-4 text-ink-subtle" aria-hidden="true" />
                        <h2 class="text-[0.9375rem] font-semibold tracking-tight text-ink">{{ t('appStore.show.licence.title') }}</h2>
                    </div>
                </template>
            </Card>
        </aside>
    </div>
</template>

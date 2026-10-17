<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Menu, Search, X } from 'lucide-vue-next';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'reka-ui';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Backdrop from '@/Core/Components/Backdrop.vue';
import Kbd from '@/Core/Components/Kbd.vue';
import Toaster from '@/Core/Components/Toaster.vue';
import { useShellHeight } from '@/Core/composables/useFullHeightPage';
import CommandPalette, { type Command } from './CommandPalette.vue';
import SidebarNav, { type ShellNavSection } from './SidebarNav.vue';

/**
 * The frame shared by the workspace and the Super Admin panel: a floating
 * glass sidebar, a floating top bar and the page beneath them. Neither slab
 * touches the edge of the window - that detachment is part of the identity.
 *
 * Slots: `brand` (top of the sidebar), `sidebar-footer`, `topbar-start`,
 * `topbar-end`, and the page itself.
 */
defineProps<{
    sections: ShellNavSection[];
    /** Extra entries for the Ctrl/Cmd+K palette, beyond the navigation. */
    commands?: Command[];
}>();

const { t } = useI18n();
// A page that asked for it (useFullHeightPage) fills the window below the top bar and scrolls inside its own panes.
const { fullHeight } = useShellHeight();

const mobileNavOpen = ref(false);
const paletteOpen = ref(false);
// The search control in the top bar: the palette opens attached under it.
const paletteAnchor = ref<HTMLElement | null>(null);
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

function onKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value = !paletteOpen.value;
    }
}

let stopNavigateListener: (() => void) | undefined;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    stopNavigateListener = router.on('navigate', () => {
        mobileNavOpen.value = false;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    stopNavigateListener?.();
});
</script>

<template>
    <Backdrop variant="shell" />

    <a
        href="#main"
        class="glass-solid sr-only z-[70] rounded-control px-4 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-4 focus:left-4"
    >
        {{ t('common.skipToContent') }}
    </a>

    <!--
        Desktop sidebar. Always dark: `data-theme="dark"` re-scopes every colour token (and `color-scheme`, for the
        scrollbar) inside it to the dark palette, whose contrast is already tested, while the page keeps its own theme.
        `glass-solid` (92% opaque) rather than `glass` (62%): over a light page the thinner glass turns a muddy grey and
        lets the backdrop's colour show through.
    -->
    <aside data-theme="dark" class="glass-solid fixed inset-y-3 left-3 z-30 hidden w-[15.5rem] flex-col rounded-panel lg:flex">
        <div class="p-3"><slot name="brand" /></div>
        <SidebarNav :sections="sections" class="min-h-0 flex-1 overflow-y-auto px-3 pb-3" />
        <div v-if="$slots['sidebar-footer']" class="border-t border-line p-3"><slot name="sidebar-footer" /></div>
    </aside>

    <!-- Mobile navigation drawer -->
    <DialogRoot v-model:open="mobileNavOpen">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-[rgb(8_14_12/0.42)] backdrop-blur-[3px] data-[state=open]:animate-fade lg:hidden" />
            <!-- Dark like the desktop sidebar. -->
            <DialogContent
                data-theme="dark"
                class="glass-solid fixed inset-y-2 left-2 z-50 flex w-[min(18rem,calc(100vw-1rem))] flex-col rounded-panel focus:outline-none data-[state=open]:animate-fade lg:hidden"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ t('shell.nav.primary') }}</DialogTitle>
                    <DialogDescription>{{ t('shell.nav.primary') }}</DialogDescription>
                </VisuallyHidden>
                <div class="flex items-start gap-2 p-3">
                    <div class="min-w-0 flex-1"><slot name="brand" /></div>
                    <DialogClose
                        class="flex size-9 shrink-0 items-center justify-center rounded-control text-ink-subtle hover:bg-hover hover:text-ink"
                        :aria-label="t('shell.nav.closeMenu')"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </DialogClose>
                </div>
                <SidebarNav :sections="sections" class="min-h-0 flex-1 overflow-y-auto px-3 pb-3" />
                <div v-if="$slots['sidebar-footer']" class="border-t border-line p-3"><slot name="sidebar-footer" /></div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>

    <div :class="['flex flex-col lg:pl-[17rem]', fullHeight ? 'h-dvh' : 'min-h-dvh']" :data-full-height="fullHeight || undefined">
        <header class="sticky top-0 z-20 px-2 pt-2 sm:px-3 sm:pt-3 lg:pl-0">
            <!-- Three parts: the place on the left, the palette in the middle, the person's controls on the right. The
                 two sides grow alike, so the palette stays centred. -->
            <div class="glass flex h-14 items-center gap-2 rounded-card px-2.5 sm:px-3">
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <button
                        type="button"
                        class="flex size-9 shrink-0 items-center justify-center rounded-control text-ink-muted hover:bg-hover hover:text-ink lg:hidden"
                        :aria-label="t('shell.nav.openMenu')"
                        @click="mobileNavOpen = true"
                    >
                        <Menu class="size-[1.125rem]" aria-hidden="true" />
                    </button>

                    <div class="min-w-0 flex-1"><slot name="topbar-start" /></div>
                </div>

                <button
                    ref="paletteAnchor"
                    type="button"
                    class="glass-well flex h-9 shrink-0 items-center gap-2 rounded-control pr-1.5 pl-2.5 text-[0.8125rem] text-ink-subtle transition-colors hover:text-ink sm:w-60 lg:w-80"
                    :aria-label="t('shell.palette.hint')"
                    @click="paletteOpen = true"
                >
                    <Search class="size-4 shrink-0" aria-hidden="true" />
                    <span class="hidden flex-1 text-left sm:block">{{ t('shell.palette.open') }}</span>
                    <span class="hidden items-center gap-0.5 sm:flex" aria-hidden="true">
                        <Kbd>{{ isMac ? '⌘' : 'Ctrl' }}</Kbd>
                        <Kbd>K</Kbd>
                    </span>
                </button>

                <div class="flex flex-1 items-center justify-end gap-2">
                    <slot name="topbar-end" />
                </div>
            </div>
        </header>

        <main
            id="main"
            tabindex="-1"
            :class="[
                'focus:outline-none sm:px-3 lg:pr-6 lg:pl-3',
                fullHeight ? 'flex min-h-0 flex-1 flex-col px-2 pt-3 pb-2 sm:pb-3' : 'flex-1 px-2 pt-6 pb-12',
            ]"
        >
            <div :class="['mx-auto w-full max-w-[88rem] animate-rise', fullHeight ? 'flex min-h-0 flex-1 flex-col' : '']">
                <slot />
            </div>
        </main>
    </div>

    <CommandPalette v-model:open="paletteOpen" :sections="sections" :commands="commands ?? []" :anchor="paletteAnchor" />
    <Toaster />
</template>

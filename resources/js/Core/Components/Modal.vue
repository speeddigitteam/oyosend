<script setup lang="ts">
import { X } from 'lucide-vue-next';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    VisuallyHidden,
} from 'reka-ui';
import { useI18n } from 'vue-i18n';

type Size = 'sm' | 'md' | 'lg';

/**
 * Dialog built on reka-ui: focus is trapped and restored, Escape and the
 * backdrop close it, and the title/description are announced.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        size?: Size;
        /** Keep the dialog open on backdrop click / Escape (e.g. while saving). */
        persistent?: boolean;
    }>(),
    { size: 'md', description: undefined },
);

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const SIZES: Record<Size, string> = {
    sm: 'max-w-sm',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
};

function guard(event: Event, persistent: boolean): void {
    if (persistent) {
        event.preventDefault();
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-[rgb(8_14_12/0.42)] backdrop-blur-[3px] data-[state=open]:animate-fade"
            />
            <DialogContent
                :class="[
                    'glass-solid fixed top-1/2 left-1/2 z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-panel',
                    'data-[state=open]:animate-pop focus:outline-none',
                    SIZES[size],
                ]"
                @escape-key-down="guard($event, persistent)"
                @pointer-down-outside="guard($event, persistent)"
            >
                <header class="flex items-start justify-between gap-4 px-6 pt-5 pb-1">
                    <div class="min-w-0">
                        <DialogTitle class="font-display text-lg font-semibold tracking-tight text-ink">
                            {{ title }}
                        </DialogTitle>
                        <DialogDescription v-if="description" class="mt-1 text-[0.8125rem] text-ink-subtle">
                            {{ description }}
                        </DialogDescription>
                        <VisuallyHidden v-else as-child>
                            <DialogDescription>{{ title }}</DialogDescription>
                        </VisuallyHidden>
                    </div>
                    <DialogClose
                        v-if="!persistent"
                        class="-mt-1 -mr-2 flex size-8 shrink-0 items-center justify-center rounded-control text-ink-subtle transition-colors hover:bg-hover hover:text-ink"
                        :aria-label="t('common.actions.close')"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </DialogClose>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="flex flex-wrap items-center justify-end gap-2 border-t border-line px-6 py-4">
                    <slot name="footer" />
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

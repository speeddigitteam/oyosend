import { computed, getCurrentInstance, onBeforeUnmount, ref, toValue, watchEffect, type MaybeRefOrGetter } from 'vue';

/** How many mounted pages currently ask for the full-height frame (normally zero or one). */
const requests = ref(0);

/**
 * Lets a page fill the window below the shell's top bar instead of growing with its content, for a screen whose
 * panes scroll on their own (the unified inbox). The page then lays itself out as a flex column
 * (`flex min-h-0 flex-1 flex-col`). Every other page keeps the normal, scrolling frame.
 *
 * Pass a getter to switch it on and off while the page stays mounted (the inbox's board view scrolls normally).
 */
export function useFullHeightPage(enabled: MaybeRefOrGetter<boolean> = true): void {
    let active = false;

    const set = (next: boolean): void => {
        if (next !== active) {
            requests.value += next ? 1 : -1;
            active = next;
        }
    };

    watchEffect(() => set(toValue(enabled)));

    if (getCurrentInstance()) {
        onBeforeUnmount(() => set(false));
    }
}

/** Read by the shell: whether the page on screen asked for the full-height frame. */
export function useShellHeight() {
    return { fullHeight: computed(() => requests.value > 0) };
}

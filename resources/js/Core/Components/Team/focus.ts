import { watch, type Ref } from 'vue';

/**
 * Keeps keyboard focus somewhere useful after a dialog closes.
 *
 * The dialog itself returns focus to whatever was focused when it opened.
 * That is not enough in two cases on these pages: a dialog opened from a menu
 * item (the item is gone by then), and a dialog whose opener left the page
 * with the action it confirmed (a removed member, a withdrawn invitation).
 * Both would drop focus on <body> and send a keyboard user back to the top.
 *
 * @param open      The dialog's open state.
 * @param selector  Element to return to, if it still exists. Without one, or
 *                  when it is gone, focus goes to the page's main region.
 */
export function useFocusReturn(open: Ref<boolean>, selector?: () => string | null): void {
    watch(
        open,
        (isOpen) => {
            if (isOpen) {
                return;
            }

            // After the dialog's own focus handling, which runs on a zero timeout.
            window.setTimeout(() => {
                const query = selector?.();
                const preferred = query ? document.querySelector<HTMLElement>(query) : null;

                if (preferred) {
                    preferred.focus();

                    return;
                }

                if (document.activeElement === null || document.activeElement === document.body) {
                    document.getElementById('main')?.focus({ preventScroll: true });
                }
            }, 0);
        },
        { flush: 'post' },
    );
}

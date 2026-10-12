import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/** Whether the viewport is at least `px` wide. True where `matchMedia` does not exist (tests, old engines). */
export function useMinWidth(px: number): Ref<boolean> {
    const matches = ref(true);
    let query: MediaQueryList | null = null;

    const update = (): void => {
        matches.value = query?.matches ?? true;
    };

    onMounted(() => {
        if (typeof window.matchMedia !== 'function') {
            return;
        }

        query = window.matchMedia(`(min-width: ${px}px)`);
        matches.value = query.matches;
        query.addEventListener('change', update);
    });

    onBeforeUnmount(() => {
        query?.removeEventListener('change', update);
    });

    return matches;
}

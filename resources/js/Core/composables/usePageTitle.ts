import { readonly, ref } from 'vue';

const title = ref('');

/**
 * The title of the page currently on screen, shared between the page (which
 * knows it) and the shell's breadcrumb (which shows it). PageHeader sets it;
 * a page without a PageHeader can call `setPageTitle()` itself.
 */
export function usePageTitle() {
    return {
        pageTitle: readonly(title),
        setPageTitle: (next: string): void => {
            title.value = next;
        },
    };
}

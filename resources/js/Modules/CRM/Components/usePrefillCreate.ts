import { onMounted, ref, watch, type Ref } from 'vue';
import type { Option } from './types';

/**
 * Opens a create form once, with a contact preselected, when the page was reached from a customer view
 * (`?create_for=`). The parameter is removed from the address so a reload or a redirect back does not reopen it.
 */
export function usePrefillCreate(prefill: Option | null, canCreate: boolean): { open: Ref<boolean>; initialContactId: Ref<string | null> } {
    const open = ref(false);
    const initialContactId = ref<string | null>(null);

    watch(open, (shown) => {
        if (!shown) initialContactId.value = null;
    });

    onMounted(() => {
        if (!canCreate || prefill === null) return;
        initialContactId.value = prefill.id;
        open.value = true;

        const url = new URL(window.location.href);
        if (url.searchParams.has('create_for')) {
            url.searchParams.delete('create_for');
            window.history.replaceState(window.history.state, '', url.toString());
        }
    });

    return { open, initialContactId };
}

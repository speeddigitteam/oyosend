import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * What the signed-in person may do in this workspace, for showing or hiding
 * controls. This is presentation only - the server authorises every request
 * again and is the only thing that actually grants access.
 */
export function useAccess() {
    const page = usePage();

    const permissions = computed(() => new Set(page.props.auth?.permissions ?? []));

    return {
        can: (permission: string): boolean => permissions.value.has(permission),
        canAny: (...names: string[]): boolean => names.some((name) => permissions.value.has(name)),
        isOwner: computed(() => page.props.auth?.membership?.isOwner === true),
    };
}

import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * What the signed-in operator's role may do in the Super Admin panel, for
 * showing or hiding controls and links. Presentation only: every panel route
 * checks its ability again on the server.
 */
export function useAbilities() {
    const page = usePage();

    const abilities = computed(() => new Set(page.props.auth?.abilities ?? []));

    return {
        /** Whether the role holds a `platform.*` ability, e.g. `platform.billing.view`. */
        allows: (ability: string): boolean => abilities.value.has(ability),
    };
}

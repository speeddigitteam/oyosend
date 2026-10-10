import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import type { Paginated } from '@/Core/types';

/** Query parameters of a list; an empty string means "not set". */
type Query = Record<string, string>;

const SEARCH_DELAY = 300;

function withoutEmpty(query: Query): Query {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== ''));
}

function same(a: Query, b: Query): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return [...keys].every((key) => (a[key] ?? '') === (b[key] ?? ''));
}

/**
 * Server-side filtering for the lists of the contact directory.
 *
 * The search box is applied a moment after typing stops; any other filter is
 * applied at once with `apply({ company: id })`. The controls start from what
 * the server applied and are not written back from later responses: an answer
 * to an earlier keystroke must never replace what has been typed since.
 */
export function useDirectoryFilters(options: {
    /** Address of the list. */
    url: () => string;
    /** The filters the server applied to this response, `search` among them. */
    applied: () => Query;
    /** The paginator of the list. */
    list: () => Paginated<unknown>;
    /** Props to ask for again when a filter changes. */
    only: string[];
}): {
    search: Ref<string>;
    loading: Ref<boolean>;
    apply: (changes?: Query) => void;
} {
    const search = ref(options.applied().search ?? '');
    const loading = ref(false);

    // The filters of the latest request, answered or not; guards against asking for the same list twice.
    let requested: Query = { ...options.applied() };
    let timer: ReturnType<typeof setTimeout> | undefined;
    let latestVisit = 0;

    function apply(changes: Query = {}): void {
        clearTimeout(timer);

        const next: Query = { ...requested, ...changes, search: search.value.trim() };

        if (same(next, requested)) {
            return;
        }

        requested = next;

        const visit = ++latestVisit;

        // No `page` in the query: a changed filter always starts again from the first page.
        router.get(options.url(), withoutEmpty(next), {
            only: options.only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                // A newer visit cancels this one; only the latest may settle things.
                if (visit !== latestVisit) {
                    return;
                }

                loading.value = false;

                // What the server applied - still the old filters if the request failed, so it can be sent again.
                requested = { ...options.applied() };
            },
        });
    }

    watch(search, () => {
        clearTimeout(timer);
        timer = setTimeout(apply, SEARCH_DELAY);
    });

    onBeforeUnmount(() => clearTimeout(timer));

    /*
     * Deleting the last record on the last page leaves the list on a page that
     * no longer exists. Step back to the one that does.
     */
    function leaveEmptyPage(): void {
        const { data, current_page: current, last_page: last } = options.list();

        if (data.length > 0 || current <= 1) {
            return;
        }

        router.get(
            options.url(),
            { ...withoutEmpty(options.applied()), ...(last > 1 ? { page: String(last) } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    onMounted(leaveEmptyPage);
    watch(options.list, leaveEmptyPage);

    return { search, loading, apply };
}

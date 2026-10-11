import { ref } from 'vue';

const STORAGE_KEY = 'oyosend.nav.collapsed';

/**
 * Which sidebar groups this person has collapsed.
 *
 * Kept in localStorage - a per-viewer convenience, never state the server needs.
 * Every access is wrapped: storage can be blocked or empty, and the sidebar
 * must render without it. The set is module-level so the desktop sidebar and
 * the mobile drawer stay in step.
 */
function read(): string[] {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed.filter((entry): entry is string => typeof entry === 'string') : [];
    } catch {
        return [];
    }
}

function write(value: string[]): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch {
        // Storage unavailable: the choice lasts until the page is reloaded.
    }
}

const collapsed = ref<string[]>(read());

export function useNavGroups() {
    function isCollapsed(id: string): boolean {
        return collapsed.value.includes(id);
    }

    function toggle(id: string): void {
        collapsed.value = isCollapsed(id) ? collapsed.value.filter((entry) => entry !== id) : [...collapsed.value, id];
        write(collapsed.value);
    }

    /** Forget the in-memory state and read storage again. */
    function reload(): void {
        collapsed.value = read();
    }

    return { collapsed, isCollapsed, toggle, reload };
}

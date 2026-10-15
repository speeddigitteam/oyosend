/**
 * How the people list is shown: a dense list (Odoo's list view) or cards (its kanban). The choice is a per-viewer
 * convenience kept in this browser only; the list itself always comes from the server.
 */
export type DirectoryView = 'list' | 'grid';

const STORAGE_KEY = 'oyo:contacts:view';

export function storedView(): DirectoryView {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'grid' ? 'grid' : 'list';
    } catch {
        // Storage blocked (private window, previews): the list.
        return 'list';
    }
}

export function storeView(view: DirectoryView): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, view);
    } catch {
        // Not kept; the page still switches.
    }
}

/** Up to two letters for an avatar: the first letter of the first and last word ("Kamrul Hasan" → "KH"). */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/u).filter((word) => word !== '');
    const first = Array.from(words[0] ?? '')[0] ?? '';
    const last = words.length > 1 ? (Array.from(words[words.length - 1] ?? '')[0] ?? '') : '';

    return (first + last).toLocaleUpperCase() || '?';
}

/** The server's sort keys, and the list column each one belongs to. */
export const SORT_COLUMNS: Record<string, string> = { name: 'name', company: 'company', email: 'email', city: 'location', created: 'createdAt' };

export const SORT_KEYS = Object.keys(SORT_COLUMNS);

/** The server sort key of a list column, or null when the column cannot be sorted. */
export function sortKeyOf(column: string): string | null {
    return SORT_KEYS.find((key) => SORT_COLUMNS[key] === column) ?? null;
}

import { computed, ref } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'oyo:theme';

const preference = ref<ThemePreference>('system');
const systemDark = ref(false);
let started = false;

function read(): ThemePreference {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return stored === 'light' || stored === 'dark' ? stored : 'system';
    } catch {
        return 'system';
    }
}

function apply(theme: ResolvedTheme): void {
    document.documentElement.dataset.theme = theme;
    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', theme === 'dark' ? '#0a110f' : '#ece7df');
}

const resolved = computed<ResolvedTheme>(() =>
    preference.value === 'system' ? (systemDark.value ? 'dark' : 'light') : preference.value,
);

function start(): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    preference.value = read();

    const media = window.matchMedia?.('(prefers-color-scheme: dark)');

    if (media) {
        systemDark.value = media.matches;
        media.addEventListener('change', (event) => {
            systemDark.value = event.matches;
            apply(resolved.value);
        });
    }

    apply(resolved.value);
}

/**
 * Light / dark / follow-the-system theme. The choice is per device, so it is
 * kept in localStorage; an inline script in the root template applies it
 * before first paint.
 */
export function useTheme() {
    start();

    function set(next: ThemePreference): void {
        preference.value = next;

        try {
            if (next === 'system') {
                window.localStorage.removeItem(STORAGE_KEY);
            } else {
                window.localStorage.setItem(STORAGE_KEY, next);
            }
        } catch {
            // Storage can be unavailable (private mode); the choice then lasts for this page only.
        }

        apply(resolved.value);
    }

    function toggle(): void {
        set(resolved.value === 'dark' ? 'light' : 'dark');
    }

    return { preference, resolved, set, toggle };
}

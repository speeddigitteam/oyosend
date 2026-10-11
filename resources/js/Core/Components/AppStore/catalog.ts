import { useI18n } from 'vue-i18n';
import type { StoreApp } from './types';

/**
 * Localised app text. Manifest strings are frozen into catalogue versions on the
 * server, so translations live in the locale files under `appStore.catalog.{appKey}`.
 * Anything without a translation falls back to the text the server sent.
 */
export function useAppCatalog() {
    const { t, te } = useI18n();

    function pick(path: string, fallback: string): string;
    function pick(path: string, fallback: string | null): string | null;
    function pick(path: string, fallback: string | null): string | null {
        return fallback !== null && te(path) ? t(path) : fallback;
    }

    return {
        name: (app: Pick<StoreApp, 'key' | 'name'>): string => pick(`appStore.catalog.${app.key}.name`, app.name),
        description: (app: Pick<StoreApp, 'key' | 'description'>): string =>
            pick(`appStore.catalog.${app.key}.description`, app.description),
        features: (app: Pick<StoreApp, 'key' | 'features'>): string[] =>
            app.features.map((feature, index) => pick(`appStore.catalog.${app.key}.features.${index}`, feature)),
        releaseNotes: (app: Pick<StoreApp, 'key' | 'releaseNotes'>): string | null =>
            pick(`appStore.catalog.${app.key}.releaseNotes`, app.releaseNotes),
    };
}

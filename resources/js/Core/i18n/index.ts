import { createI18n } from 'vue-i18n';
import bn from './locales/bn';
import en from './locales/en';

/**
 * English is the source of truth for message keys: every other locale is
 * typed against it, so a missing or misspelt key is a compile error
 * (`npm run typecheck`), not a blank label at runtime.
 */
export type MessageSchema = typeof en;

export const SUPPORTED_LOCALES = ['en', 'bn'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, bn },
    missingWarn: false,
    fallbackWarn: false,
});

export function isSupportedLocale(locale: string): locale is SupportedLocale {
    return (SUPPORTED_LOCALES as readonly string[]).includes(locale);
}

export function setLocale(locale: string): void {
    const next: SupportedLocale = isSupportedLocale(locale) ? locale : 'en';

    i18n.global.locale.value = next;

    if (typeof document !== 'undefined') {
        document.documentElement.lang = next;
    }
}

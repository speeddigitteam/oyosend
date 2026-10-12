import { useI18n } from 'vue-i18n';

/**
 * Looks a server value up in the `communication` messages and falls back to
 * the raw value, so a value a newer server sends is shown instead of a key.
 */
export function useCommunicationLabels() {
    const { t, te } = useI18n();

    function label(group: string, value: string | null | undefined): string {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        const key = `communication.${group}.${value}`;

        return te(key) ? t(key) : value;
    }

    /** A failure code as a sentence; an unknown code is shown rather than hidden. */
    function failure(code: string | null): string {
        if (code === null || code === '') {
            return '';
        }

        const key = `communication.failure.${code}`;

        return te(key) ? t(key) : t('communication.failure.other', { code });
    }

    return { label, failure };
}

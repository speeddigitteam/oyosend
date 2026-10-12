import { useI18n } from 'vue-i18n';

/**
 * Labels for codes the server sends. A code this build has no text for (a provider's failure code, say) is
 * shown as a generic phrase rather than as a raw key.
 */
export function useEmailLabels() {
    const { t, te } = useI18n();

    return {
        reason(code: string | null | undefined): string {
            if (code === null || code === undefined || code === '') {
                return t('emailMarketing.common.dash');
            }

            const key = `emailMarketing.reason.${code}`;

            return te(key) ? t(key) : t('emailMarketing.reason.other');
        },
        pause(code: string | null | undefined): string {
            const key = `emailMarketing.pauseReason.${code ?? ''}`;

            return te(key) ? t(key) : t('emailMarketing.pauseReason.other');
        },
    };
}

import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { basisPointsToPercent } from './whatsappFormat';

/** Figures, reasons and sentences of the WhatsApp app in the person's language and numerals. */
export function useWhatsappFormat() {
    const { t, te } = useI18n();
    const format = useFormat();

    /** Basis points as a percentage with at most one decimal; "not reported" when there is no basis. */
    function percent(basisPoints: number | null | undefined): string {
        const value = basisPointsToPercent(basisPoints);

        return value === null ? t('whatsapp.common.none') : `${format.number(value, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}%`;
    }

    /**
     * A machine reason (an opt-in answer, a send refusal, a provider failure code) as a sentence. Codes the app
     * does not know are shown generically with the code itself, never hidden.
     */
    function reason(code: string | null | undefined): string {
        if (code === null || code === undefined || code === '') {
            return t('whatsapp.common.none');
        }

        return te(`whatsapp.reasons.${code}`) ? t(`whatsapp.reasons.${code}`) : t('whatsapp.reasons.other', { code });
    }

    /** "5 h 20 min" style text for a duration in whole minutes. */
    function duration(hours: number, minutes: number): string {
        if (hours > 0) {
            return t('whatsapp.duration.hoursMinutes', { hours: format.number(hours), minutes: format.number(minutes) });
        }

        return t('whatsapp.duration.minutes', { minutes: format.number(minutes) });
    }

    return { percent, reason, duration };
}

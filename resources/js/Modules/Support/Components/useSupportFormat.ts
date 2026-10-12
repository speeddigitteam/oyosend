import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { basisPointsToPercent, durationParts, fileSize, hundredthsToRating } from './supportFormat';

/** Report and ticket figures in the person's language and numerals. */
export function useSupportFormat() {
    const { t } = useI18n();
    const format = useFormat();

    /** Whole minutes as "2 d 3 h", "4 h 5 min" or "12 min". */
    function duration(minutes: number | null | undefined): string {
        if (minutes === null || minutes === undefined) {
            return t('support.common.none');
        }

        const parts = durationParts(minutes);

        if (parts.days > 0) {
            return t('support.duration.daysHours', { days: format.number(parts.days), hours: format.number(parts.hours) });
        }

        if (parts.hours > 0) {
            return t('support.duration.hoursMinutes', { hours: format.number(parts.hours), minutes: format.number(parts.minutes) });
        }

        return t('support.duration.minutes', { minutes: format.number(parts.minutes) });
    }

    /** Basis points as a percentage, at most one decimal. */
    function percent(basisPoints: number | null | undefined): string {
        const value = basisPointsToPercent(basisPoints);

        return value === null ? t('support.common.none') : `${format.number(value, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}%`;
    }

    /** Hundredths as x.xx. */
    function rating(hundredths: number | null | undefined): string {
        const value = hundredthsToRating(hundredths);

        return value === null ? t('support.common.none') : format.number(value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function bytes(size: number): string {
        const { value, unit } = fileSize(size);

        return t(`support.size.${unit}`, { value: format.number(value) });
    }

    return { duration, percent, rating, bytes };
}

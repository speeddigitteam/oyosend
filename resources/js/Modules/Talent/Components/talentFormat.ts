import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';

export { fromMinorUnits, toMinorUnits } from '@/Core/lib/minorUnits';

/** Review ratings add up to exactly this many basis points. */
export const TOTAL_WEIGHT_BP = 10_000;

/** Sum of competency weights in basis points; blank or invalid weights count as zero. */
export function weightsTotal(weights: (number | string | null | undefined)[]): number {
    return weights.reduce<number>((sum, value) => {
        const number = typeof value === 'number' ? value : Number(String(value ?? '').trim());

        return sum + (Number.isInteger(number) && number > 0 ? number : 0);
    }, 0);
}

/** Percent text ("25" or "12.5") to integer basis points, or null when it is not an exact value of at most two decimals. */
export function percentToBp(text: string): number | null {
    const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(text.trim());

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0);
}

/** Integer basis points as percent text for a form field: 1250 is "12.5". */
export function bpToPercentText(bp: number): string {
    const whole = Math.floor(bp / 100);
    const fraction = String(bp % 100).padStart(2, '0').replace(/0+$/, '');

    return fraction === '' ? String(whole) : `${whole}.${fraction}`;
}

/** Whole number typed in a form, or null (negatives allowed when `signed`). */
export function parseInteger(text: string | number, signed = false): number | null {
    const value = String(text).trim();

    if (!(signed ? /^-?\d{1,15}$/ : /^\d{1,15}$/).test(value)) {
        return null;
    }

    return Number(value);
}

/** "4.35" or "4,5" typed in a form to hundredths (435, 450), or null when it is not a score of at most two decimals. */
export function scoreToHundredths(text: string): number | null {
    const match = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(text.trim());

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0);
}

/** The score held in hundredths as plain text ("4.35"), for tests and exports. Pages use `useTalentFormat().score`. */
export function scorePlain(hundredths: number): string {
    return `${Math.floor(hundredths / 100)}.${String(hundredths % 100).padStart(2, '0')}`;
}

/** Display helpers that follow the person's numerals and language. */
export function useTalentFormat() {
    const { t } = useI18n();
    const format = useFormat();

    return {
        /** A score held in hundredths: 435 is "4.35", in the person's numerals. */
        score: (hundredths: number | null | undefined): string =>
            hundredths === null || hundredths === undefined
                ? t('talent.common.dash')
                : format.number(hundredths / 100, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        /** Basis points as a percent: 7500 is "75%". */
        percent: (bp: number | null | undefined): string =>
            bp === null || bp === undefined
                ? t('talent.common.dash')
                : format.number(bp / 10_000, { style: 'percent', minimumFractionDigits: 0, maximumFractionDigits: 2 }),
        days: (count: number | null | undefined): string =>
            count === null || count === undefined
                ? t('talent.common.dash')
                : t(count === 1 ? 'talent.units.dayOne' : 'talent.units.dayMany', { count: format.number(count) }),
        /** Whole minutes as "1 h 30 min". */
        duration: (minutes: number): string => {
            const hours = Math.floor(minutes / 60);
            const rest = minutes % 60;

            if (hours === 0) {
                return t('talent.units.minutes', { count: format.number(rest) });
            }

            return rest === 0
                ? t('talent.units.hours', { count: format.number(hours) })
                : t('talent.units.hoursMinutes', { hours: format.number(hours), minutes: format.number(rest) });
        },
        size: (bytes: number): string =>
            bytes >= 1_048_576
                ? t('talent.units.mb', { count: format.number(bytes / 1_048_576, { maximumFractionDigits: 1 }) })
                : t('talent.units.kb', { count: format.number(Math.max(1, bytes / 1024), { maximumFractionDigits: 0 }) }),
        /** "09:30" in the person's numerals. */
        clock: (value: string): string => {
            const [hours, minutes] = value.split(':').map(Number) as [number, number];
            const two = { minimumIntegerDigits: 2, useGrouping: false };

            return `${format.number(hours, two)}:${format.number(minutes, two)}`;
        },
        /** Goal values are plain integers with an optional unit. */
        value: (amount: number, unit: string | null): string => `${format.number(amount)}${unit ? ` ${unit}` : ''}`,
    };
}

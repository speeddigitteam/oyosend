import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { basisPointsToRatio, durationParts, enumKey, hundredthsToNumber, isCurrencyCode, isReported } from './reportFormat';
import type { Cell, ReportColumn, Row } from './types';

function toNumber(value: Cell): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value)) {
        return Number(value);
    }

    return null;
}

/**
 * Turns a raw report cell into text for the person's language, numerals and timezone. A missing
 * value returns null so the caller can show the "not reported" dash instead of a zero.
 */
export function useReportFormat() {
    const { t, te } = useI18n();
    const format = useFormat();

    function minutes(value: number): string {
        const { negative, hours, minutes: rest } = durationParts(value);
        const sign = negative ? '-' : '';
        const params = { hours: format.number(hours), minutes: format.number(rest) };

        if (hours === 0) {
            return sign + t('reports.units.minutes', params);
        }

        return sign + t(rest === 0 ? 'reports.units.hours' : 'reports.units.hoursMinutes', params);
    }

    function money(value: Cell, column: ReportColumn, row: Row): string | null {
        const minor = typeof value === 'string' && /^-?\d+$/.test(value) ? value : toNumber(value);

        if (minor === null) {
            return null;
        }

        const code = column.currencyColumn === null ? undefined : row[column.currencyColumn];

        try {
            if (typeof minor === 'string') {
                return format.money(minor as `${bigint}`, isCurrencyCode(code) ? code.toUpperCase() : undefined);
            }

            return format.money(minor, isCurrencyCode(code) ? code.toUpperCase() : undefined);
        } catch {
            // Never print a guessed amount: show the integer next to whatever currency text came with it.
            return `${format.number(Number(minor))} ${typeof code === 'string' ? code : ''}`.trim();
        }
    }

    /** The text of one cell, or null when the figure was not reported. */
    function cell(column: ReportColumn, row: Row, reportKey: string): string | null {
        const value = row[column.key];

        if (!isReported(value)) {
            return null;
        }

        switch (column.type) {
            case 'money':
                return money(value, column, row);
            case 'integer': {
                const number = toNumber(value);

                return number === null ? String(value) : format.number(number);
            }
            case 'basis_points': {
                const number = toNumber(value);

                return number === null
                    ? null
                    : format.number(basisPointsToRatio(number), { style: 'percent', minimumFractionDigits: 0, maximumFractionDigits: 2 });
            }
            case 'hundredths': {
                const number = toNumber(value);

                return number === null
                    ? null
                    : format.number(hundredthsToNumber(number), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            case 'minutes': {
                const number = toNumber(value);

                return number === null ? null : minutes(number);
            }
            case 'date':
                return format.date(String(value), 'date') || String(value);
            case 'datetime':
                return format.date(String(value), 'datetime') || String(value);
            default: {
                if (typeof value === 'boolean') {
                    return value ? t('reports.common.yes') : t('reports.common.no');
                }

                const key = enumKey(reportKey, column.key, row);

                return key !== null && te(key) ? t(key) : String(value);
            }
        }
    }

    return { cell, minutes };
}

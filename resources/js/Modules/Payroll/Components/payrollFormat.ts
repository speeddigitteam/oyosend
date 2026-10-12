import { useI18n } from 'vue-i18n';
import { useFormat } from '@/Core/composables/useFormat';
import { displayAmount } from './payrollMoney';

/** Display helpers that follow the person's numerals and language. */
export function usePayrollFormat() {
    const { t } = useI18n();
    const format = useFormat();

    return {
        /** Basis points as a percentage: 750 is "7.5%", in the person's numerals. */
        percent: (bps: number): string => `${format.number(bps / 100, { maximumFractionDigits: 2 })}%`,
        /** Money; a reversal payslip's amounts are shown negated. */
        money: (amount: number, currency: string, isReversal = false): string =>
            format.money(displayAmount(amount, isReversal), currency),
        /** A whole number of days. */
        days: (count: number): string =>
            t(count === 1 ? 'payroll.units.dayOne' : 'payroll.units.dayMany', { count: format.number(count) }),
        /** Whole minutes as "7 h 30 min". */
        duration: (minutes: number): string => {
            const hours = Math.floor(minutes / 60);
            const rest = minutes % 60;

            if (hours === 0) {
                return t('payroll.units.minutes', { count: format.number(rest) });
            }

            return rest === 0
                ? t('payroll.units.hours', { count: format.number(hours) })
                : t('payroll.units.hoursMinutes', { hours: format.number(hours), minutes: format.number(rest) });
        },
    };
}

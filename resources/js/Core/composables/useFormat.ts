import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    formatDate,
    formatMoney,
    formatNumber,
    formatRelative,
    type DateStyle,
    type FormatContext,
} from '@/Core/lib/format';

/**
 * Locale-aware formatting bound to the current workspace and person:
 * interface language, numeral system (Latin or Bengali digits), timezone and
 * currency all come from the server's shared props.
 */
export function useFormat() {
    const page = usePage();

    const context = computed<FormatContext>(() => ({
        locale: page.props.app.locale,
        numerals: page.props.app.numerals,
        timezone: page.props.app.timezone,
        currency: page.props.app.currency,
    }));

    return {
        number: (value: number, options?: Intl.NumberFormatOptions) => formatNumber(value, context.value, options),
        money: (minorUnits: number, currency?: string) => formatMoney(minorUnits, context.value, currency),
        date: (value: string | Date | null | undefined, style: DateStyle = 'date') => formatDate(value, context.value, style),
        relative: (value: string | Date | null | undefined) => formatRelative(value, context.value),
    };
}

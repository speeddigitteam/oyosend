import { useI18n } from 'vue-i18n';
import type { BadgeTone } from '@/Core/Components/Badge.vue';
import { useFormat } from '@/Core/composables/useFormat';
import { OFFLINE_GATEWAY, type GatewayOption, type InvoiceLine } from './types';

export const SUBSCRIPTION_TONES: Record<string, BadgeTone> = {
    trialing: 'info',
    active: 'ok',
    past_due: 'warn',
    expired: 'danger',
    canceled: 'neutral',
};

export const INVOICE_TONES: Record<string, BadgeTone> = {
    open: 'warn',
    overdue: 'danger',
    paid: 'ok',
    void: 'neutral',
};

export const PAYMENT_TONES: Record<string, BadgeTone> = {
    pending: 'info',
    succeeded: 'ok',
    failed: 'danger',
    canceled: 'neutral',
};

/**
 * The words for the values billing records carry (`billing.terms.*`).
 *
 * A value this build has no word for - a status added on the server, a
 * payment method named by a gateway - is shown as it arrived, not hidden.
 */
export function useBillingTerms() {
    const { t, te } = useI18n();
    const format = useFormat();

    function term(group: string, value: string | null | undefined): string {
        if (!value) {
            return '';
        }

        // Only the server's own snake_case values can be message keys.
        if (!/^[a-z][a-z_]*$/.test(value)) {
            return value;
        }

        const key = `billing.terms.${group}.${value}`;

        return te(key) || te(key, 'en') ? t(key) : value;
    }

    /** `10 Mar 2026 to 10 Apr 2026`, or an empty string when the invoice or subscription has no period. */
    function period(from: string | null | undefined, to: string | null | undefined): string {
        if (!from || !to) {
            return '';
        }

        return t('billing.document.periodRange', { from: format.date(from), to: format.date(to) });
    }

    return {
        interval: (value: string | null | undefined): string => term('interval', value),
        perInterval: (value: string | null | undefined): string => term('perInterval', value),
        subscriptionStatus: (value: string | null | undefined): string => term('subscriptionStatus', value ?? 'none'),
        invoiceStatus: (value: string, overdue = false): string => term('invoiceStatus', overdue ? 'overdue' : value),
        invoiceKind: (value: string | null | undefined): string => term('invoiceKind', value),
        paymentStatus: (value: string | null | undefined): string => term('paymentStatus', value),
        method: (value: string | null | undefined): string => term('method', value),

        /** A gateway by the name it gave itself; money an operator recorded has no gateway. */
        gateway(key: string, gateways: GatewayOption[] = []): string {
            if (key === OFFLINE_GATEWAY) {
                return t('billing.terms.offlineGateway');
            }

            return gateways.find((gateway) => gateway.key === key)?.name ?? key;
        },

        /** What an invoice line says, from its `type`. */
        line(line: InvoiceLine): string {
            if (line.type === 'plan') {
                return t('billing.terms.line.plan', { plan: line.plan ?? '', interval: term('interval', line.interval) });
            }

            if (line.type === 'app') {
                return t('billing.terms.line.app', {
                    app: line.app ?? '',
                    offer: line.offer ?? '',
                    billingType: term('appBillingType', line.billing_type),
                });
            }

            return line.type === 'credit' ? t('billing.terms.line.credit') : line.type;
        },

        period,
    };
}

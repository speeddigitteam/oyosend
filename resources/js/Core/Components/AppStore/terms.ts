import { useI18n } from 'vue-i18n';
import type { AppBillingType, AppCategory, AppSubscriptionStatus, InstallationStatus } from './types';

export function useAppStoreTerms() {
    const { t } = useI18n();

    return {
        category: (value: AppCategory): string => t(`appStore.terms.category.${value}`),
        billingType: (value: AppBillingType): string => t(`appStore.terms.billingType.${value}`),
        installationStatus: (value: InstallationStatus): string => t(`appStore.terms.installationStatus.${value}`),
        subscriptionStatus: (value: AppSubscriptionStatus): string => t(`appStore.terms.subscriptionStatus.${value}`),
    };
}

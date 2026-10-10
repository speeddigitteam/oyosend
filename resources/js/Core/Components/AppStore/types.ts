export type AppCategory =
    | 'hrm_payroll'
    | 'crm_sales'
    | 'project_management'
    | 'invoicing'
    | 'social_media'
    | 'email_marketing'
    | 'sms_marketing'
    | 'whatsapp_marketing'
    | 'workflow_automation'
    | 'ai_tools'
    | 'customer_support'
    | 'accounting_billing'
    | 'reporting'
    | 'productivity'
    | 'integrations'
    | 'utilities';

export type AppBillingType = 'free' | 'monthly' | 'yearly' | 'one_time' | 'usage_based' | 'enterprise';
export type InstallationStatus = 'pending_payment' | 'active' | 'inactive' | 'uninstalled';
export type AppSubscriptionStatus = 'pending' | 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired';
export type LicenceStatus = 'active' | 'expired' | 'revoked';

export interface AppOffer {
    key: string;
    name: string;
    type: AppBillingType;
    currency: string | null;
    /** Integer minor units. */
    amount: number | null;
    trial_days: number;
    public: boolean;
}

export interface AppInstallation {
    id: string;
    status: InstallationStatus;
    installedAt: string | null;
    activatedAt: string | null;
    deactivatedAt: string | null;
    uninstalledAt: string | null;
    installedVersion: string | null;
}

export interface AppSubscription {
    id: string;
    offerKey: string;
    billingType: AppBillingType;
    status: AppSubscriptionStatus;
    currency: string;
    /** Integer minor units. */
    amount: number;
    quantity: number;
    trialEndsAt: string | null;
    periodEnd: string | null;
    graceEndsAt: string | null;
    cancelAtPeriodEnd: boolean;
    endedAt: string | null;
}

export interface AppLicence {
    id: string;
    source: 'free' | 'purchase' | 'trial' | 'manual';
    status: LicenceStatus;
    validUntil: string | null;
}

export type AppSettingType = 'text' | 'integer' | 'boolean' | 'select' | 'secret';

export interface AppSetting {
    key: string;
    label: string;
    type: AppSettingType;
    required: boolean;
    description: string | null;
    options: Record<string, string>;
    min: number | null;
    max: number | null;
    max_length: number;
    /** Secrets report presence, never their stored value. */
    hasValue: boolean;
    value?: string | number | boolean | null;
}

export interface StoreApp {
    key: string;
    name: string;
    description: string;
    category: AppCategory;
    icon: string | null;
    featured: boolean;
    popular: boolean;
    version: string;
    compatibility: string | null;
    features: string[];
    screenshots: string[];
    offers: AppOffer[];
    dependencies: string[];
    permissions: string[];
    releaseNotes: string | null;
    /** Entitled by the workspace's base plan, without a separate app purchase. */
    includedInPlan: boolean;
    installation: AppInstallation | null;
    subscription: AppSubscription | null;
    licence: AppLicence | null;
    settings: AppSetting[];
}

export interface AppStoreFilters {
    q: string;
    category: AppCategory | null;
    pricing: AppBillingType | null;
}

/**
 * Shared front-end types. Page-specific prop shapes live next to their page;
 * anything the server shares on every response is declared here.
 */

export type Area = 'central' | 'tenant' | 'platform';
export type Locale = 'en' | 'bn';
export type Numerals = 'latn' | 'beng';

export interface AppProps {
    name: string;
    area: Area;
    locale: Locale;
    fallbackLocale: Locale;
    locales: Record<string, string>;
    timezone: string;
    currency: string;
    numerals: Numerals;
}

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    initials: string;
    /** Workspace users only. */
    locale?: string | null;
    /** Platform users only. */
    role?: string;
    twoFactorEnabled: boolean;
}

export interface AuthProps {
    user: AuthUser | null;
    /** Workspace area only. */
    membership?: { id: string; isOwner: boolean } | null;
    /** Workspace area only: effective permission names, for hiding UI. Never a security boundary. */
    permissions?: string[];
}

export interface TenantProps {
    id: string;
    name: string;
    slug: string;
}

export interface NavigationItem {
    key: string;
    route: string;
    href: string;
    icon: string;
    /** Route-name pattern that marks the entry active. */
    active: string;
}

export interface NavigationSection {
    key: string;
    items: NavigationItem[];
}

export interface Workspace {
    name: string;
    slug: string;
    current: boolean;
}

export type ToastType = 'success' | 'info' | 'warning' | 'error';

export interface ToastPayload {
    type: ToastType;
    message: string;
}

export interface SharedProps {
    app: AppProps;
    auth?: AuthProps;
    tenant?: TenantProps | null;
    navigation?: NavigationSection[];
    workspaces?: Workspace[];
}

/** Laravel's length-aware paginator, as serialised for Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

/** Laravel's cursor paginator, as serialised for Inertia. */
export interface CursorPaginated<T> {
    data: T[];
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
        flashDataType: {
            toast?: ToastPayload;
            recoveryCodes?: string[];
        };
        errorValueType: string;
    }
}

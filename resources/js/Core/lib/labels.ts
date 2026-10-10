import { i18n } from '@/Core/i18n';

type Catalogue = {
    modules: Record<string, string>;
    permissionGroups: Record<string, string>;
    permissions: Record<string, { label: string; description: string }>;
    scopes: Record<string, { label: string; description: string }>;
    limits: Record<string, { label: string; description: string }>;
    events: Record<string, string>;
    eventFamilies: Record<string, string>;
};

/**
 * Labels for server-side keys that contain dots (`core.members.view`,
 * `auth.login`). vue-i18n would read the dots as nesting, so these are looked
 * up in the catalogue directly, with English and then the raw key as fallbacks.
 */
function lookup<T>(read: (catalogue: Catalogue) => T | undefined): T | undefined {
    const messages = i18n.global.messages.value as unknown as Record<string, { catalogue?: Catalogue }>;
    const active = messages[i18n.global.locale.value]?.catalogue;
    const fallback = messages.en?.catalogue;

    return (active && read(active)) ?? (fallback && read(fallback)) ?? undefined;
}

export function permissionLabel(name: string): string {
    return lookup((catalogue) => catalogue.permissions[name]?.label) ?? name;
}

export function permissionDescription(name: string): string {
    return lookup((catalogue) => catalogue.permissions[name]?.description) ?? '';
}

export function permissionGroupLabel(group: string): string {
    return lookup((catalogue) => catalogue.permissionGroups[group]) ?? group;
}

/** How far a grant reaches: `own`, `department`, `branch` or `all`. */
export function scopeLabel(scope: string): string {
    return lookup((catalogue) => catalogue.scopes[scope]?.label) ?? scope;
}

export function scopeDescription(scope: string): string {
    return lookup((catalogue) => catalogue.scopes[scope]?.description) ?? '';
}

/** Something a plan can cap, by its key: `core.members`. */
export function limitLabel(key: string): string {
    return lookup((catalogue) => catalogue.limits[key]?.label) ?? key;
}

export function limitDescription(key: string): string {
    return lookup((catalogue) => catalogue.limits[key]?.description) ?? '';
}

export function moduleLabel(key: string, fallback?: string): string {
    return lookup((catalogue) => catalogue.modules[key]) ?? fallback ?? key;
}

export function eventLabel(event: string): string {
    return lookup((catalogue) => catalogue.events[event]) ?? event;
}

export function eventFamilyLabel(family: string): string {
    return lookup((catalogue) => catalogue.eventFamilies[family]) ?? family;
}

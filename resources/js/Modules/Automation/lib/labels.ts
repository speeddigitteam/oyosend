import { i18n } from '@/Core/i18n';

type Group = { triggers?: Record<string, string>; actions?: Record<string, string> };
type Namespace = { automation?: Group; catalogue?: { automation?: Group } };

const PREFIXES = { triggers: 'automation.triggers.', actions: 'automation.actions.' } as const;

/** Modules register labels in the shared catalogue; this app's own map covers the rest. */
function read(locale: string, group: 'triggers' | 'actions', key: string): string | undefined {
    const messages = i18n.global.messages.value as unknown as Record<string, Namespace | undefined>;

    return messages[locale]?.catalogue?.automation?.[group]?.[key] ?? messages[locale]?.automation?.[group]?.[key];
}

/** `ai.text.generate` -> `Ai text generate`: only used when no translation exists for a key. */
export function humanizeKey(key: string): string {
    const text = key.split('.').slice(-2).join(' ').replace(/_/g, ' ').trim();

    return text === '' ? key : text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * A trigger or action label. The server names it by key
 * (`automation.triggers.crm.lead.created`); the keys contain dots, so they are
 * looked up in a flat map rather than through `t()`.
 */
export function definitionLabel(label: string | null | undefined, key = ''): string {
    const value = label ?? '';

    for (const group of ['triggers', 'actions'] as const) {
        if (value.startsWith(PREFIXES[group])) {
            const tail = value.slice(PREFIXES[group].length);

            return read(i18n.global.locale.value, group, tail) ?? read('en', group, tail) ?? humanizeKey(tail);
        }
    }

    return value !== '' ? value : humanizeKey(key);
}

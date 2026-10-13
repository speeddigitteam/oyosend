/**
 * Pure helpers of the "Messaging providers" page: what the form starts with
 * and what is sent. Kept out of the component so they can be tested.
 */

export type MessagingChannel = 'email' | 'sms' | 'whatsapp';

export interface ProviderField {
    key: string;
    label: string;
    type: 'text' | 'select' | 'integer' | 'secret';
    required: boolean;
    options?: string[];
    maxLength?: number;
}

export interface ProviderDefinition {
    channel: MessagingChannel;
    key: string;
    label: string;
    supportsTest: boolean;
    fields: ProviderField[];
}

export interface MessagingAccount {
    id: string;
    channel: MessagingChannel;
    provider: string;
    providerLabel: string;
    name: string;
    enabled: boolean;
    settings: Record<string, string | number | null>;
    /** Keys of the secrets that have a stored value; the values never reach the browser. */
    secretsSet: string[];
    /** null = every workspace. */
    tenantIds: string[] | null;
    supportsTest: boolean;
}

export interface AccountPayload {
    channel: MessagingChannel;
    provider: string;
    name: string;
    is_enabled: boolean;
    settings: Record<string, string | number | null>;
    secrets: Record<string, string>;
    tenant_ids: string[] | null;
}

/** Text values of the non-secret fields; stored values are filled in on edit. */
export function initialSettings(
    fields: ProviderField[],
    stored: Record<string, string | number | null> = {},
): Record<string, string> {
    const values: Record<string, string> = {};

    for (const field of fields) {
        if (field.type === 'secret') {
            continue;
        }

        const value = stored[field.key];

        if (value !== undefined && value !== null) {
            values[field.key] = String(value);
        } else {
            values[field.key] = field.type === 'select' && field.required ? (field.options?.[0] ?? '') : '';
        }
    }

    return values;
}

/** Secrets are never prefilled: an empty box means "keep the stored value". */
export function emptySecrets(fields: ProviderField[]): Record<string, string> {
    const values: Record<string, string> = {};

    for (const field of fields) {
        if (field.type === 'secret') {
            values[field.key] = '';
        }
    }

    return values;
}

export function buildPayload(input: {
    channel: MessagingChannel;
    provider: string;
    name: string;
    enabled: boolean;
    fields: ProviderField[];
    settings: Record<string, string>;
    secrets: Record<string, string>;
    allWorkspaces: boolean;
    tenantIds: string[];
}): AccountPayload {
    const settings: Record<string, string | number | null> = {};
    const secrets: Record<string, string> = {};

    for (const field of input.fields) {
        if (field.type === 'secret') {
            const value = input.secrets[field.key] ?? '';

            // Left empty: not sent, so the stored value stays.
            if (value !== '') {
                secrets[field.key] = value;
            }

            continue;
        }

        const raw = (input.settings[field.key] ?? '').trim();

        if (raw === '') {
            settings[field.key] = null;
        } else if (field.type === 'integer' && /^\d+$/.test(raw)) {
            settings[field.key] = Number.parseInt(raw, 10);
        } else {
            // A malformed number is sent as typed so the server can say what is wrong.
            settings[field.key] = raw;
        }
    }

    return {
        channel: input.channel,
        provider: input.provider,
        name: input.name.trim(),
        is_enabled: input.enabled,
        settings,
        secrets,
        tenant_ids: input.allWorkspaces ? null : [...input.tenantIds],
    };
}

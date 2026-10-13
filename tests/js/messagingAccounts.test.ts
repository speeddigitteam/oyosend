import { describe, expect, it } from 'vitest';
import { buildPayload, emptySecrets, initialSettings, type ProviderField } from '@/Platform/Components/Messaging/accounts';

const FIELDS: ProviderField[] = [
    { key: 'host', label: 'Host', type: 'text', required: true },
    { key: 'port', label: 'Port', type: 'integer', required: true },
    { key: 'encryption', label: 'Encryption', type: 'select', required: true, options: ['starttls', 'ssl'] },
    { key: 'from_name', label: 'From name', type: 'text', required: false },
    { key: 'secret', label: 'Password', type: 'secret', required: true },
];

describe('messaging account form', () => {
    it('starts a new account with defaults and no secrets', () => {
        expect(initialSettings(FIELDS)).toEqual({ host: '', port: '', encryption: 'starttls', from_name: '' });
        expect(emptySecrets(FIELDS)).toEqual({ secret: '' });
    });

    it('starts an edit from the stored settings and never from a secret', () => {
        expect(initialSettings(FIELDS, { host: 'smtp.example.com', port: 587, encryption: 'ssl', from_name: null })).toEqual({
            host: 'smtp.example.com',
            port: '587',
            encryption: 'ssl',
            from_name: '',
        });
    });

    it('builds the payload: integers as numbers, empty values as null, secrets only when typed', () => {
        const payload = buildPayload({
            channel: 'email',
            provider: 'smtp',
            name: '  Main  ',
            enabled: true,
            fields: FIELDS,
            settings: { host: 'smtp.example.com', port: '587', encryption: 'starttls', from_name: '' },
            secrets: { secret: '' },
            allWorkspaces: true,
            tenantIds: ['a'],
        });

        expect(payload).toEqual({
            channel: 'email',
            provider: 'smtp',
            name: 'Main',
            is_enabled: true,
            settings: { host: 'smtp.example.com', port: 587, encryption: 'starttls', from_name: null },
            secrets: {},
            tenant_ids: null,
        });
    });

    it('sends the typed secret and the chosen workspaces', () => {
        const payload = buildPayload({
            channel: 'email',
            provider: 'smtp',
            name: 'Main',
            enabled: false,
            fields: FIELDS,
            settings: { host: 'h', port: 'abc', encryption: 'ssl', from_name: 'Oyosend' },
            secrets: { secret: 'p4ss' },
            allWorkspaces: false,
            tenantIds: ['a', 'b'],
        });

        expect(payload.secrets).toEqual({ secret: 'p4ss' });
        expect(payload.tenant_ids).toEqual(['a', 'b']);
        expect(payload.is_enabled).toBe(false);
        // A malformed number is passed through for the server to reject.
        expect(payload.settings.port).toBe('abc');
    });
});

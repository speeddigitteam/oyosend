import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTheme } from '@/Core/composables/useTheme';
import { useToasts } from '@/Core/composables/useToasts';
import { setLocale } from '@/Core/i18n';
import { eventLabel, permissionLabel } from '@/Core/lib/labels';

describe('useToasts', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        const { toasts, dismiss } = useToasts();

        [...toasts.value].forEach((toast) => dismiss(toast.id));
        vi.useRealTimers();
    });

    it('queues a toast and removes it after its lifetime', () => {
        const { toasts, success } = useToasts();

        success('Saved');

        expect(toasts.value.map((toast) => toast.message)).toEqual(['Saved']);

        vi.advanceTimersByTime(10_000);

        expect(toasts.value).toHaveLength(0);
    });

    it('keeps errors on screen longer than confirmations', () => {
        const { toasts, success, error } = useToasts();

        success('Saved');
        error('Could not save');
        vi.advanceTimersByTime(5_000);

        expect(toasts.value.map((toast) => toast.type)).toEqual(['error']);
    });

    it('never stacks more than four', () => {
        const { toasts, info } = useToasts();

        for (let index = 1; index <= 6; index++) {
            info(`Message ${index}`);
        }

        expect(toasts.value).toHaveLength(4);
        expect(toasts.value.at(-1)?.message).toBe('Message 6');
    });

    it('can be dismissed by hand', () => {
        const { toasts, push, dismiss } = useToasts();
        const id = push('warning', 'Careful');

        dismiss(id);

        expect(toasts.value).toHaveLength(0);
    });
});

describe('useTheme', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('applies and remembers an explicit choice', () => {
        const { set, resolved } = useTheme();

        set('dark');

        expect(resolved.value).toBe('dark');
        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(window.localStorage.getItem('oyo:theme')).toBe('dark');
    });

    it('toggles between light and dark', () => {
        const { set, toggle, resolved } = useTheme();

        set('light');
        toggle();

        expect(resolved.value).toBe('dark');

        toggle();

        expect(resolved.value).toBe('light');
        expect(document.documentElement.dataset.theme).toBe('light');
    });

    it('follows the system when no choice is stored', () => {
        const { set, resolved } = useTheme();

        set('system');

        expect(window.localStorage.getItem('oyo:theme')).toBeNull();
        // The test environment reports a light system scheme.
        expect(resolved.value).toBe('light');
    });
});

describe('labels', () => {
    afterEach(() => setLocale('en'));

    it('translates keys that contain dots', () => {
        expect(permissionLabel('core.members.view')).toBe('View members');
        expect(eventLabel('auth.login')).toBe('Signed in');
    });

    it('translates CRM labels and falls back for unknown module keys', () => {
        expect(permissionLabel('crm.leads.view')).toBe('View leads');
        expect(eventLabel('crm.lead.created')).toBe('Created a lead');
        expect(permissionLabel('unknown.records.view')).toBe('unknown.records.view');
    });

    it('sets the document language with the locale', () => {
        setLocale('bn');

        expect(document.documentElement.lang).toBe('bn');

        setLocale('not-a-locale');

        expect(document.documentElement.lang).toBe('en');
    });
});

import { describe, expect, it } from 'vitest';
import en from '@/Core/i18n/locales/en/links';
import bn from '@/Core/i18n/locales/bn/links';
import { displayAddress, LINK_KINDS, linkHref } from '@/Modules/Links/lib';

describe('linkHref', () => {
    it('opens http and https addresses', () => {
        expect(linkHref('website', 'https://example.com/path?q=1')).toBe('https://example.com/path?q=1');
        expect(linkHref('tool', ' http://panel.example.com ')).toBe('http://panel.example.com/');
    });

    it('never turns another scheme into a link', () => {
        expect(linkHref('website', 'javascript:alert(1)')).toBeNull();
        expect(linkHref('document', 'data:text/html,<script>alert(1)</script>')).toBeNull();
        expect(linkHref('other', 'ftp://files.example.com')).toBeNull();
        expect(linkHref('website', 'not a url')).toBeNull();
    });

    it('builds a mailto link only from a plain email address', () => {
        expect(linkHref('email', 'support@example.com')).toBe('mailto:support@example.com');
        expect(linkHref('email', 'support@example.com?bcc=x@y.z')).toBeNull();
        expect(linkHref('email', 'https://example.com')).toBeNull();
    });
});

describe('displayAddress', () => {
    it('shows the host without www for a URL and the address for an email', () => {
        expect(displayAddress('website', 'https://www.example.com/a/b')).toBe('example.com');
        expect(displayAddress('email', 'support@example.com')).toBe('support@example.com');
    });
});

describe('links locale', () => {
    it('names every kind in both languages', () => {
        for (const kind of LINK_KINDS) {
            expect(en.kinds[kind]).toBeTruthy();
            expect(bn.kinds[kind]).toBeTruthy();
        }
    });
});

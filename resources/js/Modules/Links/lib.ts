export type LinkKind = 'website' | 'tool' | 'email' | 'document' | 'other';

export const LINK_KINDS: LinkKind[] = ['website', 'tool', 'email', 'document', 'other'];

/**
 * The `href` for a saved link, or null when the address is not one we open.
 * The server only accepts http(s) URLs and plain email addresses; this is the
 * second fence, so a `javascript:` or `data:` address can never become a link.
 */
export function linkHref(kind: LinkKind, address: string): string | null {
    const value = address.trim();

    if (kind === 'email') {
        return /^[^\s@:/?#]+@[^\s@:/?#]+$/.test(value) ? `mailto:${value}` : null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

/** A short form of the address for the card: the host (without `www.`) for a URL, the address itself for an email. */
export function displayAddress(kind: LinkKind, address: string): string {
    if (kind === 'email') {
        return address;
    }

    try {
        return new URL(address).host.replace(/^www\./, '');
    } catch {
        return address;
    }
}

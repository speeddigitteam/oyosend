import type { BadgeTone } from '@/Core/Components/Badge.vue';
import type { ChannelState, MessageStatus } from './types';

export function statusTone(status: MessageStatus): BadgeTone {
    switch (status) {
        case 'delivered':
            return 'ok';
        case 'sent':
            return 'info';
        case 'queued':
        case 'sending':
            return 'brand';
        case 'failed':
        case 'bounced':
            return 'danger';
        case 'suppressed':
            return 'warn';
        default:
            return 'neutral';
    }
}

export function severityTone(severity: string): BadgeTone {
    switch (severity) {
        case 'critical':
            return 'danger';
        case 'warning':
            return 'warn';
        case 'success':
            return 'ok';
        default:
            return 'neutral';
    }
}

export function stateTone(state: ChannelState): BadgeTone {
    return state === 'configured' ? 'ok' : 'warn';
}

/** The address a message on this channel would go to, if the contact has one. */
export function addressFor(channel: string, contact: { email: string | null; phone: string | null } | undefined): string | null {
    if (!contact) {
        return null;
    }

    return channel === 'email' ? contact.email : contact.phone;
}

/** A fresh key so a double click or a retried request sends one message. */
export function newRequestId(): string {
    const random =
        typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : `${Date.now()}${Math.random().toString(36).slice(2)}`;

    return random.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 64);
}

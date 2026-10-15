export type CallDirection = 'outbound' | 'inbound';

export type CallStatus = 'dialing' | 'ringing' | 'answered' | 'completed' | 'missed' | 'busy' | 'no_answer' | 'failed';

export interface CallRow {
    id: string;
    direction: CallDirection;
    mode: 'softphone' | 'bridge';
    status: CallStatus;
    /** E.164. */
    number: string;
    contact: { id: string; name: string } | null;
    startedAt: string;
    durationSeconds: number | null;
    /** The phone system itself reported the call. */
    confirmed: boolean;
    /** Only on the call log. */
    owner: string | null;
}

/** Badge tone for a call status. */
export function statusTone(status: CallStatus): 'ok' | 'warn' | 'neutral' | 'info' {
    switch (status) {
        case 'completed':
            return 'ok';
        case 'answered':
        case 'ringing':
        case 'dialing':
            return 'info';
        case 'missed':
        case 'busy':
        case 'no_answer':
        case 'failed':
            return 'warn';
        default:
            return 'neutral';
    }
}

/**
 * Phone numbers on the dialer, mirroring the server's `PhoneNumber::normalize()` for what the dialer needs: a
 * Bangladeshi mobile in any common spelling (Bengali digits too) becomes E.164 (`01712345678` → `+8801712345678`);
 * a foreign number must already carry its country code. The server normalises again and has the final word.
 */

const BENGALI_DIGITS: Record<string, string> = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };

export function normalizePhone(raw: string): string | null {
    let value = raw.trim().replace(/[০-৯]/g, (digit) => BENGALI_DIGITS[digit] ?? digit);

    if (value === '' || !/^\+?[0-9\s().-]+$/.test(value)) return null;

    let international = value.startsWith('+');
    let digits = value.replace(/\D+/g, '');

    if (!international && digits.startsWith('00')) {
        digits = digits.slice(2);
        international = true;
    }

    if (!international) {
        if (/^01[3-9]\d{8}$/.test(digits)) digits = `880${digits.slice(1)}`;
        else if (/^1[3-9]\d{8}$/.test(digits)) digits = `880${digits}`;
        else if (!/^8801[3-9]\d{8}$/.test(digits)) return null;
    }

    if (digits === '' || digits.startsWith('0') || digits.length < 8 || digits.length > 15) return null;
    if (digits.startsWith('880') && !/^8801[3-9]\d{8}$/.test(digits)) return null;

    value = `+${digits}`;

    return value;
}

/** `+8801712345678` → `01712-345678`; any other number stays as given. */
export function displayPhone(e164: string): string {
    const match = /^\+880(1\d{3})(\d{6})$/.exec(e164);

    return match ? `0${match[1]}-${match[2]}` : e164;
}

/** Seconds as `m:ss`, or `h:mm:ss` from an hour. */
export function formatDuration(seconds: number): string {
    const safe = Math.max(0, Math.floor(seconds));
    const h = Math.floor(safe / 3600);
    const m = Math.floor((safe % 3600) / 60);
    const s = String(safe % 60).padStart(2, '0');

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

/** The keys of the dialer keypad, with the letters printed under the digits. */
export const KEYPAD: ReadonlyArray<{ key: string; letters: string }> = [
    { key: '1', letters: '' },
    { key: '2', letters: 'ABC' },
    { key: '3', letters: 'DEF' },
    { key: '4', letters: 'GHI' },
    { key: '5', letters: 'JKL' },
    { key: '6', letters: 'MNO' },
    { key: '7', letters: 'PQRS' },
    { key: '8', letters: 'TUV' },
    { key: '9', letters: 'WXYZ' },
    { key: '*', letters: '' },
    { key: '0', letters: '+' },
    { key: '#', letters: '' },
];

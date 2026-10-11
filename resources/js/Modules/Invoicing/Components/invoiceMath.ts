import type { EditableLine, InvoiceLine, LinePayload, StoredLine, TaxRate } from './types';

/**
 * Exact integer arithmetic for the invoice form, mirroring the server's
 * InvoiceMath: money in minor units, quantity in thousandths, rates in basis
 * points, every product rounded half-up per line. The numbers shown here are
 * a preview - the server recalculates on save and is the authority.
 */

export const MAX_UNIT_PRICE = 1_000_000_000_000n;
export const MAX_QUANTITY_MILLI = 10_000_000n;
export const MAX_LINES = 200;

export interface LineTotals {
    gross: number;
    discount: number;
    net: number;
    tax: number;
    total: number;
}

export interface DocumentTotals {
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
}

export interface ResolvedLine {
    payload: LinePayload;
    totals: LineTotals;
}

/** Fraction digits of a currency (2 for BDT, 0 for JPY); 2 for anything unknown. */
export function currencyDigits(code: string): number {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        return 2;
    }
}

/**
 * "12.5" with scale 2 becomes 1250. Returns null for anything that is not a
 * plain non-negative decimal or that has more significant fraction digits
 * than the scale (nothing is rounded silently).
 */
export function parseDecimal(text: string, scale: number): number | null {
    const value = text.trim();

    if (!/^\d+(\.\d+)?$/.test(value)) return null;

    const [whole = '0', fraction = ''] = value.split('.');

    if (fraction.length > scale && /[1-9]/.test(fraction.slice(scale))) return null;

    const units = BigInt(whole + fraction.slice(0, scale).padEnd(scale, '0'));

    return units <= BigInt(Number.MAX_SAFE_INTEGER) ? Number(units) : null;
}

/** 1250 with scale 2 becomes "12.50". With `trim`, trailing zeros go: 1500 with scale 3 -> "1.5". */
export function toDecimal(units: number, scale: number, trim = false): string {
    const negative = units < 0;
    const digits = String(Math.abs(units)).padStart(scale + 1, '0');
    const whole = digits.slice(0, digits.length - scale);
    let fraction = digits.slice(digits.length - scale);

    if (trim) fraction = fraction.replace(/0+$/, '');

    return `${negative ? '-' : ''}${whole}${fraction === '' ? '' : `.${fraction}`}`;
}

/** value x multiplier / divisor, rounded half-up. All inputs non-negative. */
export function scaleHalfUp(value: bigint, multiplier: bigint, divisor: bigint): bigint {
    return (2n * value * multiplier + divisor) / (2n * divisor);
}

function safe(value: bigint): number | null {
    return value <= BigInt(Number.MAX_SAFE_INTEGER) ? Number(value) : null;
}

/** One line's amounts, or null when the inputs are outside what the server accepts. */
export function lineTotals(input: {
    quantityMilli: number;
    unitPrice: number;
    discountAmount: number | null;
    discountBps: number | null;
    taxBps: number;
}): LineTotals | null {
    const quantity = BigInt(input.quantityMilli);
    const price = BigInt(input.unitPrice);
    const taxBps = BigInt(input.taxBps);

    if (quantity < 1n || quantity > MAX_QUANTITY_MILLI) return null;
    if (price < 0n || price > MAX_UNIT_PRICE) return null;
    if (taxBps < 0n || taxBps > 10_000n) return null;

    const gross = scaleHalfUp(price, quantity, 1000n);
    let discount = BigInt(input.discountAmount ?? 0);

    if (input.discountBps !== null && input.discountBps > 0) {
        if (input.discountBps > 10_000 || (input.discountAmount ?? 0) > 0) return null;
        discount = scaleHalfUp(gross, BigInt(input.discountBps), 10_000n);
    }

    if (discount < 0n || discount > gross) return null;

    const net = gross - discount;
    const tax = scaleHalfUp(net, taxBps, 10_000n);
    const grossSafe = safe(gross);
    const discountSafe = safe(discount);
    const netSafe = safe(net);
    const taxSafe = safe(tax);
    const totalSafe = safe(net + tax);

    if (grossSafe === null || discountSafe === null || netSafe === null || taxSafe === null || totalSafe === null) return null;

    return { gross: grossSafe, discount: discountSafe, net: netSafe, tax: taxSafe, total: totalSafe };
}

export function sumLines(lines: LineTotals[]): DocumentTotals {
    const add = (pick: (line: LineTotals) => number): number => Number(lines.reduce((sum, line) => sum + BigInt(pick(line)), 0n));

    return {
        subtotal: add((line) => line.gross),
        discount: add((line) => line.discount),
        tax: add((line) => line.tax),
        total: add((line) => line.total),
    };
}

let counter = 0;

export function blankLine(defaults: Partial<EditableLine> = {}): EditableLine {
    counter += 1;

    return {
        key: `line-${counter}`,
        name: '',
        description: '',
        unit: '',
        quantity: '1',
        unitPrice: '',
        discountMode: 'none',
        discount: '',
        taxRateId: '',
        taxPercent: '',
        taxName: '',
        ...defaults,
    };
}

/** Turns what the person typed into the server payload and its preview totals, or null if not yet valid. */
export function resolveLine(line: EditableLine, digits: number, rates: TaxRate[]): ResolvedLine | null {
    const quantityMilli = parseDecimal(line.quantity, 3);
    const unitPrice = parseDecimal(line.unitPrice, digits);

    if (quantityMilli === null || unitPrice === null || line.name.trim() === '') return null;

    let discountAmount: number | null = null;
    let discountBps: number | null = null;

    if (line.discountMode !== 'none' && line.discount.trim() !== '') {
        const parsed = parseDecimal(line.discount, line.discountMode === 'percent' ? 2 : digits);

        if (parsed === null) return null;

        if (line.discountMode === 'percent') discountBps = parsed;
        else discountAmount = parsed;
    }

    let taxBps = 0;
    let taxRateId: string | null = null;
    let taxName: string | null = null;

    if (line.taxRateId === 'custom') {
        const parsed = line.taxPercent.trim() === '' ? 0 : parseDecimal(line.taxPercent, 2);

        if (parsed === null) return null;

        taxBps = parsed;
        taxName = line.taxName.trim() === '' ? null : line.taxName.trim();
    } else if (line.taxRateId !== '') {
        const rate = rates.find((item) => item.id === line.taxRateId);

        if (!rate) return null;

        taxBps = rate.rateBps;
        taxRateId = rate.id;
    }

    const totals = lineTotals({ quantityMilli, unitPrice, discountAmount, discountBps, taxBps });

    if (totals === null) return null;

    return {
        totals,
        payload: {
            name: line.name.trim(),
            description: line.description.trim() === '' ? null : line.description.trim(),
            unit: line.unit.trim() === '' ? null : line.unit.trim(),
            quantity_milli: quantityMilli,
            unit_price_amount: unitPrice,
            discount_amount: discountAmount,
            discount_bps: discountBps,
            tax_rate_id: taxRateId,
            tax_bps: taxRateId === null ? taxBps : null,
            tax_name: taxRateId === null ? taxName : null,
        },
    };
}

interface Shape {
    name: string;
    description: string | null;
    unit: string | null;
    quantityMilli: number;
    unitPrice: number;
    discountBps: number | null;
    discountAmount: number | null;
    taxRateId: string | null;
    taxName: string | null;
    taxBps: number | null;
}

function editable(shape: Shape, digits: number, rates: TaxRate[]): EditableLine {
    const known = shape.taxRateId === null ? undefined : rates.find((rate) => rate.id === shape.taxRateId);
    const named = shape.taxRateId === null && shape.taxBps
        ? rates.find((rate) => rate.rateBps === shape.taxBps && rate.name === shape.taxName)
        : undefined;
    const matched = known ?? named;
    const hasTax = (shape.taxBps ?? 0) > 0 || (shape.taxName ?? '') !== '';
    const amount = shape.discountAmount ?? 0;

    return blankLine({
        name: shape.name,
        description: shape.description ?? '',
        unit: shape.unit === 'unit' ? '' : (shape.unit ?? ''),
        quantity: toDecimal(shape.quantityMilli, 3, true),
        unitPrice: toDecimal(shape.unitPrice, digits),
        discountMode: shape.discountBps ? 'percent' : amount > 0 ? 'amount' : 'none',
        discount: shape.discountBps ? toDecimal(shape.discountBps, 2, true) : amount > 0 ? toDecimal(amount, digits) : '',
        taxRateId: matched ? matched.id : hasTax ? 'custom' : '',
        taxPercent: matched || !hasTax ? '' : toDecimal(shape.taxBps ?? 0, 2, true),
        taxName: matched || !hasTax ? '' : (shape.taxName ?? ''),
    });
}

/** A line the show page received, as the editor holds it. */
export function fromInvoiceLine(line: InvoiceLine, digits: number, rates: TaxRate[]): EditableLine {
    return editable({
        name: line.name,
        description: line.description,
        unit: line.unit,
        quantityMilli: line.quantityMilli,
        unitPrice: line.unitPriceAmount,
        discountBps: line.discountBps,
        discountAmount: line.discountAmount,
        taxRateId: line.taxRateId,
        taxName: line.taxName,
        taxBps: line.taxBps,
    }, digits, rates);
}

/** A line stored on a recurring template (snake_case, tax already resolved). */
export function fromStoredLine(line: StoredLine, digits: number, rates: TaxRate[]): EditableLine {
    return editable({
        name: line.name,
        description: line.description,
        unit: line.unit,
        quantityMilli: line.quantity_milli,
        unitPrice: line.unit_price_amount,
        discountBps: line.discount_bps,
        discountAmount: line.discount_amount,
        taxRateId: null,
        taxName: line.tax_name,
        taxBps: line.tax_bps,
    }, digits, rates);
}

export interface ResolvedLines {
    payloads: LinePayload[];
    totals: DocumentTotals;
    lineTotals: (LineTotals | null)[];
    valid: boolean;
}

/** Resolves every line; `valid` is false while any line is incomplete. */
export function resolveAll(lines: EditableLine[], digits: number, rates: TaxRate[]): ResolvedLines {
    const resolved = lines.map((line) => resolveLine(line, digits, rates));
    const good = resolved.filter((item): item is ResolvedLine => item !== null);

    return {
        payloads: good.map((item) => item.payload),
        totals: sumLines(good.map((item) => item.totals)),
        lineTotals: resolved.map((item) => item?.totals ?? null),
        valid: lines.length > 0 && lines.length <= MAX_LINES && good.length === lines.length,
    };
}

/** A new idempotency key: unique per dialog open, within the characters the server allows. */
export function newIdempotencyKey(): string {
    const random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;

    return `pay-${random}`;
}

/** Today's date as Y-m-d in the given IANA timezone. */
export function todayIn(timezone: string, now: Date = new Date()): string {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    } catch {
        return now.toISOString().slice(0, 10);
    }
}

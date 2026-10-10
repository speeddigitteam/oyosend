type QuoteSubmissionLine = Record<string, unknown> & {
    product_id?: string | null;
    unit_price_amount?: number | null;
};

type QuoteSubmission = Record<string, unknown> & {
    price_list_id?: string | null;
    lines: QuoteSubmissionLine[];
};

/** Let the backend resolve quantity tiers when a catalogue line uses a price list. */
export function omitTieredPriceOverrides<T extends QuoteSubmission>(data: T): T {
    if (!data.price_list_id) return data;

    return {
        ...data,
        lines: data.lines.map((line) => {
            if (!line.product_id) return line;
            const { unit_price_amount: _override, ...tieredLine } = line;
            return tieredLine;
        }),
    } as T;
}

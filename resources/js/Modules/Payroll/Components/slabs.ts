/** One editable band of a rule set. Amounts are minor units; the rate is basis points. */
export interface SlabDraft {
    key: number;
    from: number | null;
    to: number | null;
    rateBps: number | null;
    fixed: number | null;
}

export type SlabProblem = 'incomplete' | 'first' | 'gap' | 'order' | 'open' | null;

/**
 * The client-side shape check of a slab table, mirroring what the server
 * enforces: bands are contiguous half-open ranges starting at 0, and only the
 * last one is open ended. The server remains the authority.
 */
export function slabProblem(slabs: SlabDraft[]): SlabProblem {
    if (slabs.length === 0 || slabs.some((slab) => slab.from === null || slab.rateBps === null)) {
        return 'incomplete';
    }

    if (slabs[0]!.from !== 0) {
        return 'first';
    }

    for (let index = 0; index < slabs.length; index += 1) {
        const slab = slabs[index]!;
        const last = index === slabs.length - 1;

        if (last) {
            if (slab.to !== null) {
                return 'open';
            }

            continue;
        }

        if (slab.to === null || slab.to <= slab.from!) {
            return 'order';
        }

        if (slabs[index + 1]!.from !== slab.to) {
            return 'gap';
        }
    }

    return null;
}

/** The payload rows the server takes. */
export function slabPayload(slabs: SlabDraft[]): Array<{ from: number; to: number | null; rate_bps: number; fixed: number }> {
    return slabs.map((slab) => ({
        from: slab.from ?? 0,
        to: slab.to,
        rate_bps: slab.rateBps ?? 0,
        fixed: slab.fixed ?? 0,
    }));
}

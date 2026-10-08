/**
 * Turns the free-form `properties` of an audit entry into rows a person can
 * read. The server writes a few shapes:
 *
 *  - a model change:   `{ old: { status: 'active' }, attributes: { status: 'suspended' } }`
 *  - a model creation: `{ attributes: { name: 'Sales', ... } }`
 *  - a role change:    `{ old: ['Manager'], attributes: ['Admin'] }` (lists, no field names)
 *  - anything else:    `{ role: 'Manager' }`, `{ added: [...], removed: [...] }`
 *
 * Whatever does not fit one of these still comes out as a row, so nothing the
 * trail recorded is hidden.
 */

export interface AuditEntry {
    id: string;
    /** Dotted event key, e.g. `member.role_changed`. */
    event: string;
    /** Who did it; `null` for the system or a signed-out visitor. */
    actor: string | null;
    actorType: string | null;
    subject: string | null;
    subjectType: string | null;
    properties: Record<string, unknown> | unknown[] | null;
    ipAddress: string | null;
    createdAt: string;
}

export type DetailRow =
    /** "field: before → after" */
    | { kind: 'change'; field: string | null; before: unknown; after: unknown }
    /** "field: value" */
    | { kind: 'value'; field: string | null; value: unknown };

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isBlank(value: unknown): boolean {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}

export function detailRows(properties: AuditEntry['properties'] | undefined): DetailRow[] {
    if (properties === null || properties === undefined) {
        return [];
    }

    if (!isRecord(properties)) {
        return isBlank(properties) ? [] : [{ kind: 'value', field: null, value: properties }];
    }

    const { old, attributes, ...rest } = properties;
    const hasOld = 'old' in properties;
    const rows: DetailRow[] = [];

    if (isRecord(old) || isRecord(attributes)) {
        // PHP writes an empty set of attributes as `[]`, hence the fallbacks.
        const before = isRecord(old) ? old : {};
        const after = isRecord(attributes) ? attributes : {};
        const fields = [...new Set([...Object.keys(after), ...Object.keys(before)])];

        for (const field of fields) {
            if (hasOld) {
                rows.push({ kind: 'change', field, before: before[field] ?? null, after: after[field] ?? null });
            } else if (!isBlank(after[field])) {
                // A creation lists every column; the ones left empty say nothing.
                rows.push({ kind: 'value', field, value: after[field] });
            }
        }
    } else if (hasOld) {
        if (!isBlank(old) || !isBlank(attributes)) {
            rows.push({ kind: 'change', field: null, before: old ?? null, after: attributes ?? null });
        }
    } else if (!isBlank(attributes)) {
        rows.push({ kind: 'value', field: null, value: attributes });
    }

    for (const [field, value] of Object.entries(rest)) {
        if (!isBlank(value)) {
            rows.push({ kind: 'value', field, value });
        }
    }

    return rows;
}

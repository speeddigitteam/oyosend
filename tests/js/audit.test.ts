import { describe, expect, it } from 'vitest';
import { detailRows } from '@/Core/Components/Audit/properties';

describe('detailRows', () => {
    it('has nothing to show for an entry without properties', () => {
        expect(detailRows(null)).toEqual([]);
        expect(detailRows(undefined)).toEqual([]);
        expect(detailRows([])).toEqual([]);
        expect(detailRows({})).toEqual([]);
    });

    it('shows a model change field by field, before and after', () => {
        expect(detailRows({ old: { status: 'active' }, attributes: { status: 'suspended' } })).toEqual([
            { kind: 'change', field: 'status', before: 'active', after: 'suspended' },
        ]);
    });

    it('keeps a field that only one side of a change mentions', () => {
        expect(detailRows({ old: { description: 'Sales team' }, attributes: { name: 'Sales' } })).toEqual([
            { kind: 'change', field: 'name', before: null, after: 'Sales' },
            { kind: 'change', field: 'description', before: 'Sales team', after: null },
        ]);
    });

    it('lists what a new record was created with, leaving out empty columns', () => {
        expect(detailRows({ attributes: { name: 'Sales', description: null, notes: '' } })).toEqual([{ kind: 'value', field: 'name', value: 'Sales' }]);
    });

    it('shows a change recorded as plain lists as one unnamed row', () => {
        expect(detailRows({ old: ['Manager'], attributes: ['Admin'] })).toEqual([{ kind: 'change', field: null, before: ['Manager'], after: ['Admin'] }]);
    });

    it('treats the empty attribute set PHP writes as [] like an empty record', () => {
        expect(detailRows({ old: { status: 'active' }, attributes: [] })).toEqual([{ kind: 'change', field: 'status', before: 'active', after: null }]);
    });

    it('shows any other property as a value and skips blank ones', () => {
        expect(detailRows({ role: 'Manager', added: ['core.members.view'], removed: [], note: null })).toEqual([
            { kind: 'value', field: 'role', value: 'Manager' },
            { kind: 'value', field: 'added', value: ['core.members.view'] },
        ]);
    });

    it('never hides what the trail recorded, whatever its shape', () => {
        expect(detailRows(['unexpected'])).toEqual([{ kind: 'value', field: null, value: ['unexpected'] }]);
        expect(detailRows({ old: { a: 1 }, attributes: { a: 2 }, via: 'console' })).toEqual([
            { kind: 'change', field: 'a', before: 1, after: 2 },
            { kind: 'value', field: 'via', value: 'console' },
        ]);
    });
});

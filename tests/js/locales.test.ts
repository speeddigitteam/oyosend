import { describe, expect, it } from 'vitest';
import bn from '@/Core/i18n/locales/bn';
import en from '@/Core/i18n/locales/en';

type Tree = { [key: string]: Tree | string };

function flatten(tree: Tree, prefix = ''): Map<string, string> {
    const flat = new Map<string, string>();

    for (const [key, value] of Object.entries(tree)) {
        // `|` cannot appear in a real key, so it is a safe path separator
        // even for catalogue keys that contain dots (`core.members.view`).
        const path = prefix === '' ? key : `${prefix}|${key}`;

        if (typeof value === 'string') {
            flat.set(path, value);
        } else {
            for (const [childPath, childValue] of flatten(value, path)) {
                flat.set(childPath, childValue);
            }
        }
    }

    return flat;
}

function placeholders(message: string): string[] {
    return [...message.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();
}

const english = flatten(en as unknown as Tree);
const bengali = flatten(bn as unknown as Tree);

describe('message catalogues', () => {
    it('has the same keys in Bengali as in English', () => {
        expect([...bengali.keys()].sort()).toEqual([...english.keys()].sort());
    });

    it('has no empty messages', () => {
        for (const [locale, messages] of [
            ['en', english],
            ['bn', bengali],
        ] as const) {
            const empty = [...messages].filter(([, value]) => value.trim() === '').map(([key]) => `${locale}:${key}`);

            expect(empty).toEqual([]);
        }
    });

    it('keeps the same placeholders in every translation', () => {
        const mismatched = [...english]
            .filter(([key, value]) => bengali.has(key) && placeholders(value).join() !== placeholders(bengali.get(key)!).join())
            .map(([key]) => key);

        expect(mismatched).toEqual([]);
    });

    it('avoids characters vue-i18n treats as syntax', () => {
        // `@` starts a linked message and `|` separates plural forms; either
        // one in ordinary text silently breaks the message.
        const offending = [
            ...[...english].map(([key, value]) => ['en', key, value] as const),
            ...[...bengali].map(([key, value]) => ['bn', key, value] as const),
        ]
            .filter(([, , value]) => /[@|]/.test(value))
            .map(([locale, key]) => `${locale}:${key}`);

        expect(offending).toEqual([]);
    });
});

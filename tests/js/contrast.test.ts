import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Recomputes text contrast on glass from the design tokens themselves.
 *
 * Glass is translucent, so the colour behind a piece of text depends on what
 * the backdrop happens to show there. This takes the worst case - the middle
 * of a disc at the strongest opacity any layout uses, where the blur cannot
 * soften it - and checks every text/surface pair the components produce.
 *
 * The sheen and the film grain are left out: both are a few percent and the
 * sheen only ever helps in the light theme.
 */

type Rgb = [number, number, number];
type Colour = { rgb: Rgb; alpha: number };

const AA = 4.5;

// Vitest runs from the project root.
const read = (path: string): string => readFileSync(resolve(process.cwd(), path), 'utf8');

const stylesheet = read('resources/css/app.css');

function block(selector: string): Map<string, string> {
    const start = stylesheet.indexOf(`${selector} {`);
    const body = stylesheet.slice(start, stylesheet.indexOf('\n}', start));

    return new Map([...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map((match) => [match[1]!, match[2]!.trim()]));
}

function parse(value: string): Colour {
    const hex = /^#([0-9a-f]{6})$/i.exec(value);

    if (hex) {
        const n = parseInt(hex[1]!, 16);

        return { rgb: [(n >> 16) & 255, (n >> 8) & 255, n & 255], alpha: 1 };
    }

    const functional = /^rgb\((\d+) (\d+) (\d+)(?: \/ ([\d.]+))?\)$/.exec(value);

    if (functional) {
        return { rgb: [Number(functional[1]), Number(functional[2]), Number(functional[3])], alpha: Number(functional[4] ?? 1) };
    }

    throw new Error(`Cannot read the colour "${value}". Tokens used for text or surfaces must be #rrggbb or rgb(r g b / a).`);
}

const over = (top: Colour, bottom: Rgb): Rgb => top.rgb.map((channel, i) => channel * top.alpha + bottom[i]! * (1 - top.alpha)) as Rgb;

/** The CSS `saturate()` filter, which the glass applies to whatever is behind it. */
function saturate(rgb: Rgb, amount: number): Rgb {
    const [r, g, b] = rgb;
    const matrix = [
        [0.213 + 0.787 * amount, 0.715 - 0.715 * amount, 0.072 - 0.072 * amount],
        [0.213 - 0.213 * amount, 0.715 + 0.285 * amount, 0.072 - 0.072 * amount],
        [0.213 - 0.213 * amount, 0.715 - 0.715 * amount, 0.072 + 0.928 * amount],
    ];

    return matrix.map((row) => Math.min(255, Math.max(0, row[0]! * r + row[1]! * g + row[2]! * b))) as Rgb;
}

function luminance(rgb: Rgb): number {
    const [r, g, b] = rgb.map((channel) => {
        const c = channel / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    }) as Rgb;

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: Rgb, b: Rgb): number {
    const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * The strongest filled disc any component places, read from the classes that
 * place them - in whatever order they are written. A disc without an opacity
 * class is solid.
 */
function strongestDisc(): number {
    const components = (readdirSync(resolve(process.cwd(), 'resources/js'), { recursive: true }) as string[])
        .filter((path) => path.endsWith('.vue'))
        .map((path) => read(`resources/js/${path}`));

    const opacities = components.flatMap((source) =>
        [...source.matchAll(/class="([^"]*bg-\(--orb-[a-z]+\)[^"]*)"/g)].map((match) => {
            const opacity = /(?:^|\s)opacity-(\d+)(?:\s|$)/.exec(match[1]!);

            return opacity ? Number(opacity[1]) / 100 : 1;
        }),
    );

    expect(opacities.length).toBeGreaterThan(0);

    return Math.max(...opacities);
}

const glassSaturation = Number(/@utility glass \{[^}]*saturate\(([\d.]+)\)/.exec(stylesheet)?.[1]);

const light = block(':root');
const themes: Record<string, Map<string, string>> = {
    light,
    // The dark theme only redefines what differs.
    dark: new Map([...light, ...block("[data-theme='dark']")]),
};

describe.each(Object.keys(themes))('contrast on glass, %s theme', (name) => {
    const tokens = themes[name]!;
    const colour = (token: string): Colour => {
        const value = tokens.get(token);

        if (value === undefined) {
            throw new Error(`Token --${token} is missing from app.css.`);
        }

        return parse(value);
    };
    const solid = (token: string): Rgb => colour(token).rgb;

    function surfaces(): Map<string, Rgb> {
        const disc = strongestDisc();
        const grounds = ['canvas', 'field-a', 'field-b', 'field-c'];
        const behind = new Map<string, Rgb>(grounds.map((ground) => [ground, solid(ground)]));

        for (const orb of ['orb-ember', 'orb-pine', 'orb-butter']) {
            for (const ground of grounds) {
                behind.set(`${orb} on ${ground}`, over({ rgb: solid(orb), alpha: disc }, solid(ground)));
            }
        }

        return new Map([...behind].map(([where, rgb]) => [where, over(colour('glass'), saturate(rgb, glassSaturation))]));
    }

    function failures(pairs: Array<{ text: string; on?: string }>): string[] {
        const failed: string[] = [];

        for (const [where, glass] of surfaces()) {
            for (const { text, on } of pairs) {
                const surface = on === undefined ? glass : over(colour(on), glass);
                const ratio = contrast(solid(text), surface);

                if (ratio < AA) {
                    failed.push(`--${text} on ${on === undefined ? 'glass' : `--${on}`} over ${where}: ${ratio.toFixed(2)}`);
                }
            }
        }

        return failed;
    }

    it('reads the glass saturation from the stylesheet', () => {
        expect(glassSaturation).toBeGreaterThan(0);
    });

    it('keeps body, secondary and caption text readable', () => {
        expect(failures([{ text: 'ink' }, { text: 'ink-muted' }, { text: 'ink-subtle' }, { text: 'brand-text' }, { text: 'accent-text' }])).toEqual([]);
    });

    it('keeps text readable on hovered and selected rows', () => {
        expect(
            failures([
                { text: 'ink', on: 'hover' },
                { text: 'ink-muted', on: 'hover' },
                { text: 'ink-subtle', on: 'hover' },
                { text: 'ink', on: 'active' },
                { text: 'brand-text', on: 'active' },
            ]),
        ).toEqual([]);
    });

    it('keeps status text readable, bare and on its soft chip', () => {
        const tones = ['ok', 'warn', 'danger', 'info'];

        expect(
            failures([
                ...tones.map((tone) => ({ text: tone })),
                ...tones.map((tone) => ({ text: tone, on: `${tone}-soft` })),
                { text: 'brand-text', on: 'brand-soft' },
                { text: 'accent-text', on: 'accent-soft' },
            ]),
        ).toEqual([]);
    });

    it('keeps typed text and placeholders readable in form controls', () => {
        expect(
            failures([
                { text: 'ink', on: 'control-sunken' },
                { text: 'ink-subtle', on: 'control-sunken' },
                { text: 'ink', on: 'control' },
            ]),
        ).toEqual([]);
    });

    it('keeps chat text readable on the wallpaper and on both kinds of bubble', () => {
        // The message area lays its warm surface and then the line pattern over the glass; a bubble sits on top of that.
        const failed: string[] = [];

        for (const [where, glass] of surfaces()) {
            const wall = over(colour('chat-wallpaper'), over(colour('chat-surface'), glass));
            const layers: Array<[string, Rgb, string[]]> = [
                ['the wallpaper', wall, ['ink', 'ink-muted', 'ink-subtle', 'brand-text']],
                ['--chat-in', over(colour('chat-in'), wall), ['ink', 'ink-muted', 'ink-subtle', 'brand-text', 'accent-text', 'danger']],
                ['--chat-out', over(colour('chat-out'), wall), ['ink', 'ink-muted', 'ink-subtle', 'brand-text', 'accent-text', 'danger']],
            ];

            for (const [name, surface, texts] of layers) {
                for (const text of texts) {
                    const ratio = contrast(solid(text), surface);

                    if (ratio < AA) {
                        failed.push(`--${text} on ${name} over ${where}: ${ratio.toFixed(2)}`);
                    }
                }
            }
        }

        expect(failed).toEqual([]);
    });

    it('keeps an unread count readable on its solid accent pill', () => {
        expect(contrast(solid('accent-ink'), solid('accent'))).toBeGreaterThanOrEqual(AA);
    });

    it('keeps labels readable on filled buttons and solid panels', () => {
        expect(contrast(solid('brand-ink'), solid('brand'))).toBeGreaterThanOrEqual(AA);
        expect(contrast(solid('brand-ink'), solid('brand-hover'))).toBeGreaterThanOrEqual(AA);
        expect(contrast(solid('canvas'), solid('danger'))).toBeGreaterThanOrEqual(AA);

        // Menus, dialogs and toasts: near-opaque, so the canvas stands in for whatever is underneath.
        const panel = over(colour('glass-strong'), solid('canvas'));

        for (const text of ['ink', 'ink-muted', 'ink-subtle', 'brand-text', 'danger']) {
            expect(contrast(solid(text), panel), `--${text} on a solid panel`).toBeGreaterThanOrEqual(AA);
        }
    });
});

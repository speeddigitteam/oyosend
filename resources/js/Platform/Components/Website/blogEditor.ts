/**
 * The blog editor's small pieces of logic (Super Admin > Website > Blog), kept apart from the page so they can be
 * tested: the address made from a title, the length of an article, and the Markdown the toolbar writes.
 */

export type Locale = 'en' | 'bn';

export interface Question {
    q: string;
    a: string;
}

export interface Words {
    title: string;
    summary: string;
    body: string;
    cover_alt: string;
    meta_title: string;
    meta_description: string;
    faq: Question[];
}

export function emptyWords(): Words {
    return { title: '', summary: '', body: '', cover_alt: '', meta_title: '', meta_description: '', faq: [] };
}

/** Whether anything is written in a language: the server's rule for which languages an article exists in. */
export function isWritten(words: Words): boolean {
    return (
        words.title.trim() !== '' ||
        words.summary.trim() !== '' ||
        words.body.trim() !== '' ||
        words.faq.some((item) => item.q.trim() !== '' || item.a.trim() !== '')
    );
}

/** An address from a title, the way the server makes one: Latin letters and digits joined by single hyphens. */
export function slugify(title: string): string {
    return title
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80)
        .replace(/-+$/g, '');
}

/** Words a reader gets through in a minute, as the website counts them (App\Core\Website\Blog\BlogMarkdown). */
const WORDS_PER_MINUTE: Record<Locale, number> = { en: 220, bn: 160 };

export function wordCount(markdown: string): number {
    const text = markdown.replace(/\]\([^)]*\)/g, ' ').replace(/[#*_>`~|[\]!-]+/g, ' ').trim();

    return text === '' ? 0 : text.split(/\s+/).length;
}

export function readingMinutes(markdown: string, locale: Locale): number {
    return Math.max(1, Math.ceil(wordCount(markdown) / WORDS_PER_MINUTE[locale]));
}

export interface Edit {
    text: string;
    /** The selection after the change. */
    start: number;
    end: number;
}

/** Put marks around the selection (bold, italic), or around a sample word when nothing is selected. */
export function wrap(text: string, start: number, end: number, before: string, after: string, sample: string): Edit {
    const selected = text.slice(start, end) || sample;
    const next = text.slice(0, start) + before + selected + after + text.slice(end);

    return { text: next, start: start + before.length, end: start + before.length + selected.length };
}

/** Start every selected line with a prefix (a heading, a list, a quote); a numbered list counts up. */
export function prefixLines(text: string, start: number, end: number, prefix: string | ((index: number) => string), sample: string): Edit {
    const lineStart = text.lastIndexOf('\n', start - 1) + 1;
    const lineEnd = text.indexOf('\n', end) === -1 ? text.length : text.indexOf('\n', end);
    const block = text.slice(lineStart, lineEnd) || sample;
    const lines = block.split('\n').map((line, index) => (typeof prefix === 'function' ? prefix(index) : prefix) + line.replace(/^(#{1,6} |[-*] |\d+\. |> )/, ''));
    const replaced = lines.join('\n');
    const next = text.slice(0, lineStart) + replaced + text.slice(lineEnd);

    return { text: next, start: lineStart, end: lineStart + replaced.length };
}

/** Insert a block on lines of its own, with a blank line around it so Markdown reads it as one. */
export function insertBlock(text: string, at: number, block: string): Edit {
    const before = text.slice(0, at);
    const after = text.slice(at);
    const lead = before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
    const trail = after === '' || after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n';
    const next = before + lead + block + trail + after;
    const start = before.length + lead.length;

    return { text: next, start, end: start + block.length };
}

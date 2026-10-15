import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

/*
 * Shared helpers for the session layout checks: three sizes, two themes, screenshots,
 * no horizontal page scroll, no browser errors, a visible keyboard focus.
 *
 * Screenshots go to E2E_SHOTS (outside the repository when set), else under storage/.
 * The signed-in state of each person is kept in E2E_STATE_DIR between the spec files.
 */
export const SHOTS = process.env.E2E_SHOTS ?? join(process.cwd(), 'storage', 'framework', 'testing', 'e2e', 'shots');
export const STATE_DIR = process.env.E2E_STATE_DIR ?? join(process.cwd(), 'storage', 'framework', 'testing', 'e2e', 'state');

mkdirSync(SHOTS, { recursive: true });
mkdirSync(STATE_DIR, { recursive: true });

export const SIZES = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 1024, height: 768 },
    { name: 'phone', width: 360, height: 780 },
] as const;

export const THEMES = ['light', 'dark'] as const;

export type Size = (typeof SIZES)[number];
export type Theme = (typeof THEMES)[number];

export const VISITOR = 'Session QA visitor';
export const LIVE_CHAT_ORIGINAL = join(STATE_DIR, 'live-chat-settings.json');

export const statePath = (who: string): string => join(STATE_DIR, `${who}.json`);

export async function openContext(browser: Browser, size: Size, theme: Theme, who: string | null): Promise<BrowserContext> {
    return browser.newContext({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        locale: 'en-GB',
        timezoneId: 'Asia/Dhaka',
        storageState: who === null ? undefined : statePath(who),
    });
}

/** Records what the browser complains about. Expected 404s for remote avatars and the 409 redirect protocol are ignored. */
export function collectProblems(page: Page): () => string[] {
    const problems: string[] = [];
    const ignored = (text: string): boolean => text.includes('status of 409') || /avatar|gravatar|graph\.facebook|fbcdn|ui-avatars/i.test(text);

    page.on('pageerror', (error) => problems.push(`script error: ${error.message}`));
    page.on('console', (message) => {
        if (message.type() === 'error' && !ignored(message.text())) {
            problems.push(`console: ${message.text()}`);
        }
    });
    page.on('requestfailed', (request) => {
        const failure = request.failure()?.errorText ?? '';
        // A navigation or a poll cancelled by the app itself is not a failure.
        if (/ERR_ABORTED|NS_BINDING_ABORTED/.test(failure) || ignored(request.url())) return;
        problems.push(`request failed: ${request.method()} ${request.url()} ${failure}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 400 && response.status() !== 409 && !ignored(response.url())) {
            problems.push(`http ${response.status()}: ${response.request().method()} ${response.url()}`);
        }
    });

    return () => problems.splice(0);
}

export async function shot(page: Page, name: string, fullPage = false): Promise<string> {
    const file = join(SHOTS, `${name}.png`);
    // Let colour transitions (150 ms) finish, so a chip is not caught half-way.
    await page.waitForTimeout(350);
    await page.screenshot({ path: file, fullPage });

    return file;
}

/** The page itself must not scroll sideways. Returns the widest offenders for the report. */
export async function horizontalOverflow(page: Page): Promise<{ scrollWidth: number; clientWidth: number; offenders: string[] }> {
    return page.evaluate(() => {
        const root = document.documentElement;
        const width = root.clientWidth;
        const offenders: string[] = [];

        if (root.scrollWidth > width + 1) {
            for (const element of Array.from(document.body.querySelectorAll<HTMLElement>('*'))) {
                const box = element.getBoundingClientRect();
                if (box.width === 0 || box.right <= width + 1) continue;
                // Inside something that clips or scrolls on its own: not the page's problem.
                let clipped = false;
                for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                    const style = getComputedStyle(parent);
                    if (style.overflowX !== 'visible') { clipped = true; break; }
                }
                if (clipped) continue;
                offenders.push(`${element.tagName.toLowerCase()}.${String(element.className).slice(0, 80)} right=${Math.round(box.right)}`);
                if (offenders.length > 8) break;
            }
        }

        return { scrollWidth: root.scrollWidth, clientWidth: width, offenders };
    });
}

export async function expectNoHorizontalScroll(page: Page, label: string): Promise<void> {
    const result = await horizontalOverflow(page);
    expect.soft(result.scrollWidth, `${label}: page scrolls sideways (${result.offenders.join(' | ')})`).toBeLessThanOrEqual(result.clientWidth + 1);
}

/**
 * Tabs through the first `count` focusable controls and returns those that show no focus indication
 * (no outline and no box-shadow ring). Focus inside a hidden element is skipped.
 */
export async function focusAudit(page: Page, count = 12): Promise<string[]> {
    await page.locator('body').click({ position: { x: 1, y: 1 } }).catch(() => undefined);
    const missing: string[] = [];

    for (let i = 0; i < count; i++) {
        await page.keyboard.press('Tab');
        const info = await page.evaluate(() => {
            const element = document.activeElement as HTMLElement | null;
            if (!element || element === document.body) return null;
            const style = getComputedStyle(element);
            const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
            const ring = style.boxShadow !== 'none' && style.boxShadow !== '';
            const name = (element.getAttribute('aria-label') ?? element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40);

            return { visible: outline || ring, label: `${element.tagName.toLowerCase()} "${name}"` };
        });
        if (info && !info.visible) missing.push(info.label);
    }

    return missing;
}

/** Reads the Inertia page props of the current document. */
export async function pageProps<T = Record<string, unknown>>(page: Page): Promise<T> {
    return page.evaluate(() => JSON.parse(document.querySelector('script[data-page]')?.textContent ?? '{}').props) as Promise<T>;
}

import { expect, test } from '@playwright/test';
import { admin, PASSWORD } from '../support/env';
import { collectProblems, expectNoHorizontalScroll, focusAudit, openContext, shot, SIZES, statePath, THEMES } from './kit';

/*
 * Super Admin > Integrations: the Social platform credentials (Instagram switch, TikTok, YouTube, Pinterest).
 * Read only: nothing is saved. Skipped when the panel asks for a two-step code this run cannot give.
 */
test.describe.configure({ mode: 'serial' });

let signedIn = false;

test('admin signs in', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(admin('login'));
    await page.locator('input[type="email"]').fill('admin@oyosend.test');
    await page.locator('input[type="password"]').fill(PASSWORD);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    const path = new URL(page.url()).pathname;
    test.skip(path.startsWith('/two-factor'), `the panel asks for two-step verification (${path})`);

    await context.storageState({ path: statePath('admin') });
    signedIn = true;
    await context.close();
});

for (const size of SIZES) {
    for (const theme of THEMES) {
        test(`admin integrations ${size.name} ${theme}`, async ({ browser }) => {
            test.skip(!signedIn, 'not signed in to the panel');

            const context = await openContext(browser, size, theme, 'admin');
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `admin-integrations-${size.name}-${theme}`;

            await page.goto(admin('settings/integrations'));
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-1-top`);
            await expectNoHorizontalScroll(page, `${tag} top`);

            if (theme === 'light' && size.name !== 'tablet') {
                const unfocused = await focusAudit(page, 16);
                expect.soft(unfocused, `${tag}: controls without a visible focus`).toEqual([]);
            }

            for (const [index, name] of ['instagram', 'tiktok', 'youtube', 'pinterest'].entries()) {
                const field = page.locator(`input[name^="social.${name}."], input[name^="social_${name}."]`).first();
                const card = page.locator('form').filter({ has: page.locator(`[name*="${name}"]`) }).first();
                const target = (await field.count()) ? field : card;
                if (!(await target.count())) {
                    // The Instagram card has only switches, so look for it by its heading.
                    const heading = page.getByRole('heading', { name: new RegExp(name, 'i') }).first();
                    await expect.soft(heading, `${tag}: a ${name} card`).toBeVisible();
                    await heading.scrollIntoViewIfNeeded();
                } else {
                    await target.scrollIntoViewIfNeeded();
                }
                await shot(page, `${tag}-${index + 2}-${name}`);
            }
            await expectNoHorizontalScroll(page, `${tag} scrolled`);

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });
    }
}

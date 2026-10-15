import { expect, test } from '@playwright/test';
import { workspace } from '../support/env';
import { collectProblems, expectNoHorizontalScroll, focusAudit, openContext, shot, SIZES, THEMES } from './kit';

/*
 * The unified inbox: the list and the reading pane beside it (one or the other on a phone),
 * the Unread chip, the composer and the "Suggest a reply" button.
 */
for (const size of SIZES) {
    for (const theme of THEMES) {
        test(`inbox ${size.name} ${theme}`, async ({ browser }) => {
            const context = await openContext(browser, size, theme, 'owner');
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `inbox-${size.name}-${theme}`;

            await page.goto(workspace('acme', 'communication/inbox'));
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-1-list`);
            await expectNoHorizontalScroll(page, `${tag} list`);

            if (theme === 'light') {
                const unfocused = await focusAudit(page, 16);
                expect.soft(unfocused, `${tag}: controls without a visible focus`).toEqual([]);
            }

            // Unread chip on and off again.
            await page.locator('[data-chip="unread"]').click();
            await expect(page.locator('[data-chip="unread"]')).toHaveAttribute('aria-pressed', 'true');
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-2-unread`);
            await expectNoHorizontalScroll(page, `${tag} unread`);
            await page.locator('[data-chip="unread"]').click();
            await expect(page.locator('[data-chip="unread"]')).toHaveAttribute('aria-pressed', 'false');
            await page.waitForLoadState('networkidle');

            // Open the website chat beside the list (or over it on a phone).
            const row = page.locator('[data-row-key^="support.live_chat:"]').first();
            await expect(row, 'the website chat made by 00-setup is listed').toBeVisible();
            await row.click();
            const thread = page.getByTestId('inbox-thread');
            await expect(thread).toBeVisible();
            await page.waitForLoadState('networkidle');

            const composer = thread.locator('textarea');
            await expect(composer).toBeVisible();
            await composer.fill('Thanks for writing! Yes, we deliver to Chattogram, usually within two working days.');
            await shot(page, `${tag}-3-thread`);
            await expectNoHorizontalScroll(page, `${tag} thread`);

            const suggest = await thread.getByTestId('suggest-reply').count();
            test.info().annotations.push({ type: 'suggest-reply', description: suggest > 0 ? 'shown' : 'not shown' });

            const listRow = page.locator('[data-row-key^="support.live_chat:"]').first();
            const back = thread.getByRole('button', { name: 'Back to the list' });

            if (size.name === 'phone') {
                await expect(listRow, 'on a phone the open chat replaces the list').toBeHidden();
                await expect(back).toBeVisible();
                await back.click();
                await expect(listRow).toBeVisible();
                await expect(page.getByTestId('inbox-thread')).toHaveCount(0);
                await shot(page, `${tag}-4-back`);
                await expectNoHorizontalScroll(page, `${tag} back`);
            } else {
                await expect(listRow, 'the list stays beside the open chat').toBeVisible();
                await expect(back).toBeHidden();
            }

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });
    }
}

import { expect, test } from '@playwright/test';
import { workspace } from '../support/env';
import { collectProblems, expectNoHorizontalScroll, focusAudit, openContext, shot, SIZES, THEMES } from './kit';

/*
 * The Social post composer (composer and live preview side by side from 1280 px, stacked below that, media
 * thumbnails, the sticky action bar) and the accounts page with every platform's honest availability.
 * Nothing is saved.
 */
for (const size of SIZES) {
    for (const theme of THEMES) {
        test(`social composer ${size.name} ${theme}`, async ({ browser }) => {
            const context = await openContext(browser, size, theme, 'owner');
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `social-composer-${size.name}-${theme}`;

            await page.goto(workspace('acme', 'social/posts/create'));
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-1-top`);
            await expectNoHorizontalScroll(page, `${tag} top`);

            if (theme === 'light') {
                const unfocused = await focusAudit(page, 20);
                expect.soft(unfocused, `${tag}: controls without a visible focus`).toEqual([]);
            }

            await page.getByPlaceholder('What would you like to share?').fill('Our new Eid collection is here! Free delivery inside Dhaka this week. #eid #fashion https://example.com/eid');

            // Attach the library image (made by 00-setup).
            const addMedia = page.getByTestId('add-media');
            if (await addMedia.count()) {
                await addMedia.click();
                const library = page.getByTestId('media-library');
                await expect(library).toBeVisible();
                await library.locator('input[type="checkbox"]').first().check({ force: true });
                await library.getByRole('button', { name: 'Done' }).click();
                await expect(page.getByTestId('chosen-media').locator('img').first()).toBeVisible();
            }
            await page.waitForLoadState('networkidle');

            // The preview beside the composer from 1280 px, below it otherwise.
            const preview = page.locator('#post-preview');
            const form = page.locator('form').filter({ has: page.getByTestId('composer-actions') });
            const [previewBox, formBox] = [await preview.boundingBox(), await form.boundingBox()];
            if (previewBox && formBox) {
                if (size.width >= 1280) {
                    expect.soft(previewBox.x, `${tag}: preview beside the composer`).toBeGreaterThan(formBox.x + formBox.width - 1);
                } else {
                    expect.soft(previewBox.y, `${tag}: preview below the composer`).toBeGreaterThan(formBox.y + formBox.height - 1);
                }
            }

            await page.getByTestId('media-section').scrollIntoViewIfNeeded();
            await shot(page, `${tag}-2-media`);
            await expect(page.getByTestId('composer-actions'), 'the action bar stays in view').toBeInViewport();
            await shot(page, `${tag}-3-full`, true);
            await expectNoHorizontalScroll(page, `${tag} filled`);

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });

        test(`social accounts ${size.name} ${theme}`, async ({ browser }) => {
            const context = await openContext(browser, size, theme, 'owner');
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `social-accounts-${size.name}-${theme}`;

            await page.goto(workspace('acme', 'social/accounts'));
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');

            for (const key of ['instagram', 'tiktok', 'youtube', 'pinterest']) {
                const card = page.getByTestId(`platform-${key}`);
                await expect.soft(card, `${tag}: a ${key} card`).toBeVisible();
                await expect.soft(card.getByTestId('reason'), `${tag}: ${key} says why it is unavailable`).toBeVisible();
            }

            await page.getByTestId('platform-instagram').scrollIntoViewIfNeeded();
            await shot(page, `${tag}-1`);
            await shot(page, `${tag}-2-full`, true);
            await expectNoHorizontalScroll(page, tag);

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });
    }
}

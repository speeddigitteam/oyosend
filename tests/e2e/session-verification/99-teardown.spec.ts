import { existsSync, readFileSync, rmSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { workspace } from '../support/env';
import { LIVE_CHAT_ORIGINAL, openContext, pageProps, SIZES } from './kit';

/* Puts the website chat settings back the way 00-setup found them (the chat and the image made there stay). */
test('restore the website chat settings', async ({ browser }) => {
    test.skip(!existsSync(LIVE_CHAT_ORIGINAL), 'nothing to restore');

    const original = JSON.parse(readFileSync(LIVE_CHAT_ORIGINAL, 'utf8')) as { enabled: boolean; allowedOrigins: string[] };
    const context = await openContext(browser, SIZES[0], 'light', 'owner');
    const page = await context.newPage();

    await page.goto(workspace('acme', 'support/live-chat/settings'));
    await page.getByLabel('Websites allowed to show the chat').fill(original.allowedOrigins.join('\n'));
    await page.getByLabel('Switch the website chat on').setChecked(original.enabled);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForLoadState('networkidle');

    await page.goto(workspace('acme', 'support/live-chat/settings'));
    const { settings } = await pageProps<{ settings: { enabled: boolean; allowedOrigins: string[] } }>(page);
    expect(settings.enabled).toBe(original.enabled);
    expect(settings.allowedOrigins).toEqual(original.allowedOrigins);

    rmSync(LIVE_CHAT_ORIGINAL);
    await context.close();
});

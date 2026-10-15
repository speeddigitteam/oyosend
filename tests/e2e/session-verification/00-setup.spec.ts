import { existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { signIn } from '../support/app';
import { PORT, workspace } from '../support/env';
import { LIVE_CHAT_ORIGINAL as ORIGINAL, pageProps, STATE_DIR, statePath, VISITOR } from './kit';

/*
 * Signs the owner in once and makes the little data the screens need, through the app itself:
 * the website chat switched on for the local central origin (remembered, and switched back by 99-teardown),
 * one visitor chat started from the chat page, one image in the Social media library.
 * Nothing is created twice when the run is repeated.
 */

/** The origin of the stand-in website in 30-live-chat (the central host on the app's port). */
const SITE = `http://localhost:${PORT}`;

test('sign in and prepare the demo data', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await signIn(page, 'acme', 'owner@acme.test');
    await context.storageState({ path: statePath('owner') });

    // The website chat: remember how it was, then switch it on for the local origin.
    await page.goto(workspace('acme', 'support/live-chat/settings'));
    const { settings } = await pageProps<{ settings: { enabled: boolean; allowedOrigins: string[] } }>(page);

    if (!existsSync(ORIGINAL)) {
        writeFileSync(ORIGINAL, JSON.stringify(settings));
    }

    if (!settings.enabled || !settings.allowedOrigins.includes(SITE)) {
        await page.getByLabel('Switch the website chat on').check();
        await page.getByLabel('Websites allowed to show the chat').fill([...settings.allowedOrigins, SITE].join('\n'));
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByLabel('Switch the website chat on')).toBeChecked();
        await page.waitForLoadState('networkidle');
    }

    // One visitor chat, started from the chat page as a visitor would.
    await page.goto(workspace('acme', `support/live-chat?search=${encodeURIComponent(VISITOR)}&state=all`));
    const { conversations } = await pageProps<{ conversations: { items: { id: string; title: string }[] } }>(page);

    if (!conversations.items.some((row) => row.title.includes(VISITOR))) {
        const visitor = await browser.newContext({ viewport: { width: 360, height: 780 } });
        const frame = await visitor.newPage();

        await frame.goto(workspace('acme', 'support/chat/frame'));
        await frame.locator('#chat-name').fill(VISITOR);
        await frame.locator('#chat-email').fill('session-qa-visitor@example.com');
        await frame.locator('#chat-first').fill('Hello, I would like to know whether you deliver to Chattogram, and how long it usually takes. Thank you!');
        await frame.locator('#chat-start button[type="submit"]').click();
        await expect(frame.locator('#chat-log')).toBeVisible();
        await visitor.close();
    }

    // One image in the Social media library, so the composer can show a thumbnail.
    await page.goto(workspace('acme', 'social/media'));
    const media = await pageProps<{ media?: { data?: unknown[] } | unknown[] }>(page);
    const count = Array.isArray(media.media) ? media.media.length : (media.media?.data?.length ?? 0);

    if (count === 0) {
        const art = await browser.newPage({ viewport: { width: 1200, height: 800 } });
        await art.setContent('<body style="margin:0"><div style="width:1200px;height:800px;background:linear-gradient(135deg,#0f766e,#f59e0b);display:flex;align-items:center;justify-content:center;font:bold 96px sans-serif;color:#fff">Session QA</div></body>');
        const image = join(STATE_DIR, 'session-qa.png');
        await art.screenshot({ path: image });
        await art.close();

        await page.getByRole('button', { name: /upload/i }).first().click();
        await page.locator('input[type="file"]').setInputFiles(image);
        await page.getByRole('dialog').getByRole('button', { name: /upload/i }).last().click();
        await expect(page.getByRole('dialog')).toHaveCount(0, { timeout: 30_000 });
    }

    await context.close();
});

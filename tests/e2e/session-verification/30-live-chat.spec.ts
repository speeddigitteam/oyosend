import { chromium, expect, test } from '@playwright/test';
import { central, workspace } from '../support/env';
import { collectProblems, expectNoHorizontalScroll, focusAudit, openContext, shot, SIZES, THEMES, VISITOR } from './kit';

/*
 * Support > Live chat: the list, a chat opened beside it (or alone on a phone), the "Save as contact" and
 * "Create ticket" actions; the visitor's chat page, alone and embedded by the loader on an allowed origin.
 * The ticket dialog is opened and closed, never submitted.
 */
for (const size of SIZES) {
    for (const theme of THEMES) {
        test(`live chat ${size.name} ${theme}`, async ({ browser }) => {
            const context = await openContext(browser, size, theme, 'owner');
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `livechat-${size.name}-${theme}`;

            await page.goto(workspace('acme', 'support/live-chat'));
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-1-list`);
            await expectNoHorizontalScroll(page, `${tag} list`);

            if (theme === 'light') {
                const unfocused = await focusAudit(page, 16);
                expect.soft(unfocused, `${tag}: controls without a visible focus`).toEqual([]);
            }

            await page.getByRole('link', { name: new RegExp(VISITOR) }).first().click();
            const thread = page.getByTestId('live-chat-thread');
            await expect(thread).toBeVisible();
            await page.waitForLoadState('networkidle');
            await shot(page, `${tag}-2-thread`);
            await expectNoHorizontalScroll(page, `${tag} thread`);

            const back = page.getByRole('link', { name: 'Back to chats' });
            if (size.width < 1024) {
                await expect(back).toBeVisible();
            } else {
                await expect(back).toBeHidden();
                await expect(page.getByRole('link', { name: new RegExp(VISITOR) }).first(), 'the list stays beside the chat').toBeVisible();
            }

            const createTicket = thread.getByRole('button', { name: 'Create ticket' });
            if (await createTicket.count()) {
                await createTicket.click();
                const dialog = page.getByRole('dialog');
                await expect(dialog).toBeVisible();
                await page.waitForTimeout(300);
                await shot(page, `${tag}-3-ticket-dialog`);
                await expectNoHorizontalScroll(page, `${tag} ticket dialog`);
                await page.keyboard.press('Escape');
                await expect(dialog).toHaveCount(0);
            }
            test.info().annotations.push({
                type: 'links',
                description: `save-contact=${await thread.getByRole('button', { name: 'Save as contact' }).count()} create-ticket=${await createTicket.count()}`,
            });

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });
    }
}

test('live chat: save the visitor as a contact, then the ticket dialog', async ({ browser }) => {
    const context = await openContext(browser, SIZES[0], 'light', 'owner');
    const page = await context.newPage();
    const problems = collectProblems(page);

    await page.goto(workspace('acme', 'support/live-chat'));
    await page.getByRole('link', { name: new RegExp(VISITOR) }).first().click();
    const thread = page.getByTestId('live-chat-thread');
    await expect(thread).toBeVisible();

    const save = thread.getByRole('button', { name: 'Save as contact' });
    if (await save.count()) {
        await save.click();
        await expect(thread.getByTestId('live-chat-links')).toContainText('Contact');
        await page.waitForLoadState('networkidle');
    }
    await shot(page, 'livechat-desktop-light-4-contact-saved');

    const createTicket = thread.getByRole('button', { name: 'Create ticket' });
    if (await createTicket.count()) {
        await createTicket.click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await shot(page, 'livechat-desktop-light-5-ticket-dialog');
        await page.keyboard.press('Escape');
    }

    for (const theme of THEMES) {
        const phone = await openContext(browser, SIZES[2], theme, 'owner');
        const small = await phone.newPage();
        await small.goto(page.url());
        await expect(small.getByTestId('live-chat-thread')).toBeVisible();
        await small.waitForLoadState('networkidle');
        await shot(small, `livechat-phone-${theme}-6-contact-saved`);
        if (await small.getByRole('button', { name: 'Create ticket' }).count()) {
            await small.getByRole('button', { name: 'Create ticket' }).click();
            await expect(small.getByRole('dialog')).toBeVisible();
            await small.waitForTimeout(300);
            await shot(small, `livechat-phone-${theme}-7-ticket-dialog`);
            await expectNoHorizontalScroll(small, `phone ${theme} ticket dialog`);
        }
        await phone.close();
    }

    expect.soft(problems(), 'the browser reported problems').toEqual([]);
    await context.close();
});

for (const size of [SIZES[1], SIZES[2]]) {
    for (const theme of THEMES) {
        test(`chat frame ${size.name} ${theme}`, async ({ browser }) => {
            const context = await openContext(browser, size, theme, null);
            const page = await context.newPage();
            const problems = collectProblems(page);
            const tag = `chatframe-${size.name}-${theme}`;

            await page.goto(workspace('acme', 'support/chat/frame'));
            await expect(page.locator('#chat-first')).toBeVisible();
            await shot(page, `${tag}-1-start`);
            await expectNoHorizontalScroll(page, tag);

            expect.soft(problems(), `${tag}: the browser reported problems`).toEqual([]);
            await context.close();
        });
    }
}

test('chat widget embedded on an allowed origin', async () => {
    // The stand-in website below is answered by the browser itself, so Chrome counts it as a public page and its
    // Local Network Access check refuses the loopback workspace. A real website is not on loopback; switch the check off.
    const browser = await chromium.launch({ channel: 'chrome', args: ['--disable-features=LocalNetworkAccessChecks'] });

    for (const size of [SIZES[0], SIZES[2]]) {
        const context = await openContext(browser, size, 'light', null);
        const page = await context.newPage();
        const problems = collectProblems(page);
        const host = central('__session-qa-widget');

        // A stand-in for the workspace's own website on http://localhost:8000 (an allowed origin), served by the browser.
        await page.route(host, (route) => route.fulfill({
            contentType: 'text/html; charset=utf-8',
            body: `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Shop</title></head>
<body style="margin:0;font:16px sans-serif"><h1 style="padding:16px">A shop website</h1><p style="padding:0 16px">Content.</p>
<script src="${workspace('acme', 'support/chat/widget.js')}" defer></script></body></html>`,
        }));

        await page.goto(host);
        const launcher = page.locator('button[aria-expanded]');
        await expect(launcher).toBeVisible();
        await shot(page, `widget-${size.name}-1-launcher`);
        await launcher.click();
        const frame = page.frameLocator('iframe');
        await expect(frame.locator('#chat-first')).toBeVisible();
        await page.waitForTimeout(300);
        await shot(page, `widget-${size.name}-2-open`);
        await expectNoHorizontalScroll(page, `widget ${size.name}`);

        expect.soft(problems(), `widget ${size.name}: the browser reported problems`).toEqual([]);
        await context.close();
    }

    await browser.close();
});

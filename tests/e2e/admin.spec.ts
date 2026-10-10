import { expect, test, type Page } from '@playwright/test';
import { sidebar, totp, watchForErrors } from './support/app';
import { admin, PASSWORD } from './support/env';

/*
 * The Super Admin panel on its own host, and support access: an operator
 * opening a workspace as its owner. That crosses from one origin to another
 * and back, which only a real browser can show.
 *
 * One browser session for the whole file: the operator signs in once, turns
 * two-step verification on (the panel opens nothing before that) and goes on.
 */
test.describe.configure({ mode: 'serial' });

let page: Page;

test.beforeAll(async ({ browser }) => {
    page = await (await browser.newContext()).newPage();
});

test.afterAll(async () => {
    await page.context().close();
});

test.describe('the Super Admin panel', () => {
    test('opens nothing before two-step verification is on', async () => {
        await page.goto(admin('login'));
        await page.locator('input[type="email"]').fill('admin@oyosend.test');
        await page.locator('input[type="password"]').fill(PASSWORD);
        await page.locator('button[type="submit"]').click();

        await expect(page.getByRole('heading', { name: 'Set up two-step verification', level: 1 })).toBeVisible();

        // Whatever is asked for leads back here.
        await page.goto(admin('tenants'));
        await expect(page.getByRole('heading', { name: 'Set up two-step verification', level: 1 })).toBeVisible();
    });

    test('lets the operator in once a code from the authenticator is confirmed', async () => {
        // The key shown for typing by hand, as an authenticator app would be given it.
        const secret = (await page.locator('p.select-all').innerText()).replace(/\s/g, '');

        await page.locator('input[autocomplete="one-time-code"]').fill(totp(secret));
        await page.getByRole('button', { name: 'Turn on and continue' }).click();

        await page.waitForURL((url) => !url.pathname.includes('two-factor'));
        await expect(page.getByText('Save your recovery codes')).toBeVisible();
    });

    test('lists the workspaces and shows one with its plan and people', async () => {
        const noErrors = watchForErrors(page);

        await page.goto(admin('tenants'));

        await expect(page.getByRole('link', { name: 'Acme Trading' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Globex Bangladesh' }).first()).toBeVisible();

        await page.getByRole('link', { name: 'Acme Trading' }).first().click();

        await expect(page.getByRole('heading', { name: 'Acme Trading', level: 1 })).toBeVisible();
        await expect(page.getByText('owner@acme.test').first()).toBeVisible();

        noErrors();
    });

    test('opens a workspace as its owner only with a reason, and the workspace says so on every page', async () => {
        await page.getByRole('button', { name: 'Open as owner' }).click();

        const dialog = page.getByRole('dialog');

        await dialog.getByLabel('Reason').fill('too short');
        await dialog.getByRole('button', { name: 'Open workspace' }).click();
        await expect(dialog).toBeVisible();

        await dialog.getByLabel('Reason').fill('Ticket 4521: the invoice page shows an error');
        await dialog.getByRole('button', { name: 'Open workspace' }).click();

        // On the workspace's own address now, as its owner.
        await page.waitForURL((url) => url.hostname === 'acme.localhost');

        const banner = page.getByText('Support session').first();

        await expect(banner).toBeVisible();
        await expect(page.getByText('Opened by admin@oyosend.test')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Ayesha');

        await sidebar(page).getByRole('link', { name: 'Contacts', exact: true }).click();
        await expect(page.getByText('Support session').first()).toBeVisible();

        // The owner's own account stays closed to the operator.
        const profile = await page.goto('http://acme.localhost:8010/settings/security');

        expect(profile?.status()).toBe(403);
    });

    test('ends the session from the banner, back in the panel, and both sides have a record of it', async () => {
        await page.goto('http://acme.localhost:8010/');
        await page.getByRole('button', { name: 'End session' }).click();

        await page.waitForURL((url) => url.hostname === 'admin.localhost');
        await expect(page.getByRole('heading', { name: 'Acme Trading', level: 1 })).toBeVisible();
        await expect(page.getByText('Ticket 4521: the invoice page shows an error')).toBeVisible();

        // The workspace session is gone with it.
        const workspace = await page.context().newPage();

        await workspace.goto('http://acme.localhost:8010/');
        await expect(workspace).toHaveURL(/\/login$/);
        await workspace.close();
    });
});

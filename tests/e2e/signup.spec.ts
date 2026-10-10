import { expect, test } from '@playwright/test';
import { linkFromMail, mailMark, sidebar, signIn, watchForErrors } from './support/app';
import { central, PORT, workspace } from './support/env';

/*
 * Signing up for a workspace: the form on the central site, the emailed
 * link, the confirmation, and the first-run setup of the new workspace.
 */
test.describe('signing up', () => {
    test('a company signs up, confirms by email and lands in its own workspace', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await page.goto(central('register'));
        await expect(page.getByRole('heading', { name: 'Create a workspace', level: 1 })).toBeVisible();

        await page.getByLabel('Your name').fill('Karim Uddin');
        await page.getByLabel('Work email').fill('karim@dhaka-traders.example');
        await page.getByLabel('Choose a password').fill('correct-horse-battery-1A');
        await page.getByLabel('Confirm password').fill('correct-horse-battery-1A');
        await page.getByLabel('Company name').fill('Dhaka Traders');
        await page.getByLabel('Workspace address').fill('dhaka-traders');

        const mark = mailMark();

        await page.getByRole('button', { name: 'Sign up' }).click();

        // Nothing exists yet: only a link was sent.
        await expect(page.getByRole('heading', { name: 'Check your email', level: 1 })).toBeVisible();
        await expect(page.getByText('karim@dhaka-traders.example')).toBeVisible();

        // Asked with a browser tab: only browsers resolve *.localhost by themselves.
        const probe = await page.context().newPage();
        const unconfirmed = await probe.goto(workspace('dhaka-traders', 'login'));

        expect(unconfirmed?.status()).toBe(404);
        await probe.close();

        // The link opens a page, and creating the workspace takes a deliberate click.
        const link = await linkFromMail(new RegExp(`http://localhost:${PORT}/register/confirm/[A-Za-z0-9]+`), mark);

        await page.goto(link);
        await expect(page.getByRole('heading', { name: 'Create Dhaka Traders', level: 1 })).toBeVisible();
        await expect(page.getByText(`dhaka-traders.localhost`)).toBeVisible();

        await page.getByRole('button', { name: 'Create workspace' }).click();

        // A brand-new account is taken straight into its workspace, on that workspace's own address.
        await page.waitForURL((url) => url.hostname === 'dhaka-traders.localhost');
        await expect(page.getByRole('heading', { name: 'Set up Dhaka Traders', level: 1 })).toBeVisible();

        await page.getByRole('button', { name: 'Finish setup' }).click();

        await expect(page.getByRole('heading', { level: 1 })).toContainText('Karim');
        await expect(sidebar(page).getByRole('link', { name: 'Billing', exact: true })).toBeVisible();

        noErrors();

        // The link worked once.
        await page.goto(link);
        await expect(page.getByRole('heading', { name: 'Dhaka Traders is ready', level: 1 })).toBeVisible();
    });

    test('the new owner signs in again with the password they chose', async ({ page }) => {
        await signIn(page, 'dhaka-traders', 'karim@dhaka-traders.example', 'correct-horse-battery-1A');

        await expect(page.getByRole('heading', { level: 1 })).toContainText('Karim');
    });

    test('an address that is taken or reserved is refused before anything is sent', async ({ page }) => {
        await page.goto(central('register'));

        await page.getByLabel('Your name').fill('Someone Else');
        await page.getByLabel('Work email').fill('someone@else.example');
        await page.getByLabel('Choose a password').fill('correct-horse-battery-1A');
        await page.getByLabel('Confirm password').fill('correct-horse-battery-1A');
        await page.getByLabel('Company name').fill('Acme Again');
        await page.getByLabel('Workspace address').fill('acme');
        await page.getByRole('button', { name: 'Sign up' }).click();

        await expect(page.getByText('This workspace address is not available.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Create a workspace', level: 1 })).toBeVisible();
    });
});

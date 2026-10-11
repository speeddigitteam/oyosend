import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';
import { workspace } from './support/env';

/*
 * Signing in to a workspace on its own address, and what each person finds there.
 * The workspaces and people are the seeded demo ones.
 */
test.describe('signing in to a workspace', () => {
    test('the owner is let in and finds every part of the workspace', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        await expect(page.getByRole('heading', { level: 1 })).toContainText('Ayesha');

        for (const entry of ['Dashboard', 'Contacts', 'Members', 'Roles & permissions', 'Branches & departments', 'Audit log', 'Workspace settings', 'Billing', 'App Store']) {
            await expect(sidebar(page).getByRole('link', { name: entry, exact: true })).toBeVisible();
        }

        noErrors();
    });

    test('the App Store shows the production app catalogue and an honest My Apps list', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'App Store', exact: true }).click();

        await expect(page.getByRole('heading', { name: 'App Store', level: 1 })).toBeVisible();
        await expect(page.getByText('CRM & Sales', { exact: true })).toBeVisible();
        await expect(page.getByText('Project Management', { exact: true })).toBeVisible();
        await expect(page.getByText('No optional apps are compiled into this build yet')).toHaveCount(0);
        await page.getByRole('link', { name: 'My apps', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'My apps', level: 1 })).toBeVisible();
        await expect(page.getByText('No apps installed yet')).toBeVisible();

        noErrors();
    });

    test('a wrong password is refused, and the page does not say which part was wrong', async ({ page }) => {
        await page.goto(workspace('acme', 'login'));
        await page.locator('input[type="email"]').fill('owner@acme.test');
        await page.locator('input[type="password"]').fill('not-the-password');
        await page.getByRole('button', { name: 'Sign in' }).click();

        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
        expect(new URL(page.url()).pathname).toBe('/login');
    });

    test('an account of another workspace is not let in here', async ({ page }) => {
        await page.goto(workspace('globex', 'login'));
        await page.locator('input[type="email"]').fill('owner@acme.test');
        await page.locator('input[type="password"]').fill('password');
        await page.locator('button[type="submit"]').click();

        // The same answer as for a wrong password, in the workspace's language (Bengali).
        await expect(page.locator('input[type="email"]')).toBeVisible();
        expect(new URL(page.url()).pathname).toBe('/login');
    });

    test('an employee sees what the role allows and nothing else', async ({ page }) => {
        await signIn(page, 'acme', 'employee@acme.test');

        await expect(sidebar(page).getByRole('link', { name: 'Contacts', exact: true })).toBeVisible();
        await expect(sidebar(page).getByRole('link', { name: 'Members', exact: true })).toBeVisible();

        for (const hidden of ['Billing', 'Workspace settings', 'Audit log', 'Roles & permissions']) {
            await expect(sidebar(page).getByRole('link', { name: hidden, exact: true })).toHaveCount(0);
        }

        // Not only hidden: the server refuses the address as well.
        const response = await page.goto(workspace('acme', 'billing'));

        expect(response?.status()).toBe(403);
    });

    test('the interface follows the language a person chooses', async ({ page }) => {
        await signIn(page, 'acme', 'manager@acme.test');

        await page.getByRole('button', { name: 'Language' }).click();
        await page.getByRole('menuitem', { name: /বাংলা/ }).click();

        // At once, without loading the page again.
        await expect(sidebar(page, 'প্রধান মেনু').getByRole('link', { name: 'কন্টাক্ট', exact: true })).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await expect(page.getByRole('heading', { level: 1 })).not.toContainText('Good');
    });
});

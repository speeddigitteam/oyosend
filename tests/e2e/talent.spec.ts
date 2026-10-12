import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('Talent & Performance', () => {
    test('an owner can open the talent pages from the sidebar', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        await sidebar(page).getByRole('link', { name: 'Job openings', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Job openings', level: 1 })).toBeVisible();

        // A job opening cannot be saved until it has a title.
        await page.getByRole('link', { name: 'Add job opening', exact: true }).first().click();
        await expect(page.getByRole('heading', { name: 'Add job opening', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Create job opening', exact: true })).toBeDisabled();
        await page.getByLabel('Title').fill('Accountant');
        await expect(page.getByRole('button', { name: 'Create job opening', exact: true })).toBeEnabled();

        await sidebar(page).getByRole('link', { name: 'Applications', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Applications', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Candidates', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Candidates', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Interviews', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Interviews', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Review cycles', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Review cycles', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Courses', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Courses', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Talent analytics', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Talent analytics', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Talent settings', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Talent settings', level: 1 })).toBeVisible();

        noErrors();
    });

    test('a candidate is added and appears on the candidates page', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        await sidebar(page).getByRole('link', { name: 'Candidates', exact: true }).click();
        await page.getByRole('button', { name: 'Add candidate', exact: true }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
        await dialog.getByLabel('First name').fill('Rahim');
        await dialog.getByLabel('Last name').fill('Uddin');
        await expect(dialog.getByRole('button', { name: 'Save', exact: true })).toBeEnabled();
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();

        await expect(page.getByRole('heading', { name: 'Rahim Uddin', level: 1 })).toBeVisible();

        noErrors();
    });
});

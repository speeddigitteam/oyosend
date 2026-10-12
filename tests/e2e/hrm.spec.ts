import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('HR Management', () => {
    test('an owner can open the HR pages from the sidebar', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        await sidebar(page).getByRole('link', { name: 'Employees', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Employees', level: 1 })).toBeVisible();

        // The form cannot be saved until the person has a first name.
        await page.getByRole('link', { name: 'Add employee', exact: true }).first().click();
        await expect(page.getByRole('heading', { name: 'Add employee', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add employee', exact: true })).toBeDisabled();
        await page.getByLabel('First name').fill('Nadia');
        await expect(page.getByRole('button', { name: 'Add employee', exact: true })).toBeEnabled();

        await sidebar(page).getByRole('link', { name: 'Attendance', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Attendance', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Leave', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Leave', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Holidays', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Holidays', level: 1 })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'HR settings', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'HR settings', level: 1 })).toBeVisible();

        noErrors();
    });
});

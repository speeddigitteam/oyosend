import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('Invoicing', () => {
    test('an owner can open Invoicing and start a draft invoice', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'Invoices', exact: true }).click();

        await expect(page.getByRole('heading', { name: 'Invoices', level: 1 })).toBeVisible();
        await page.getByRole('link', { name: 'New invoice', exact: true }).first().click();

        await expect(page.getByRole('heading', { name: 'New invoice', level: 1 })).toBeVisible();
        // Nothing can be saved until a customer is chosen and a line is complete.
        await expect(page.getByRole('button', { name: 'Create draft', exact: true })).toBeDisabled();

        await page.getByLabel('Item 1').fill('Consulting');
        await page.getByLabel('Unit price').fill('100.00');
        await expect(page.getByTestId('invoice-totals').getByText('Total', { exact: true })).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Receivables', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Receivables', level: 1 })).toBeVisible();

        noErrors();
    });
});

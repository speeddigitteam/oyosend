import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('Payroll', () => {
    test('an owner can open the payroll pages from the sidebar', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        const pages: Array<{ link: string; heading: string }> = [
            { link: 'Payslips', heading: 'Payslips' },
            { link: 'Payroll runs', heading: 'Payroll runs' },
            { link: 'Pay periods', heading: 'Pay periods' },
            { link: 'Salaries', heading: 'Salaries' },
            { link: 'Pay items', heading: 'Pay items' },
            { link: 'Loans and advances', heading: 'Loans and advances' },
            { link: 'Payroll reports', heading: 'Payroll reports' },
            { link: 'Salary structures', heading: 'Salary structures' },
            { link: 'Tax rules', heading: 'Tax and contribution rules' },
            { link: 'Payroll settings', heading: 'Payroll settings' },
        ];

        for (const item of pages) {
            await sidebar(page).getByRole('link', { name: item.link, exact: true }).click();
            await expect(page.getByRole('heading', { name: item.heading, level: 1 })).toBeVisible();
        }

        noErrors();
    });

    test('the rule set form says the numbers are the workspace\'s own and checks band shape', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'Tax rules', exact: true }).click();
        await expect(page.getByRole('note')).toContainText('not legal or tax advice');

        await page.getByRole('button', { name: 'Create rule set', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // A first band that does not start at zero cannot be saved.
        await expect(dialog.getByRole('button', { name: 'Create rule set', exact: true })).toBeDisabled();
        await dialog.getByLabel('Name').fill('Example rule');
        await dialog.getByLabel('Code').fill('EXAMPLE');
        await dialog.getByRole('textbox', { name: 'From' }).first().fill('10');
        await expect(dialog.getByTestId('slab-problem')).toContainText('must start at 0');

        noErrors();
    });

    test('a money field refuses an inexact amount', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'Pay items', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Pay items', level: 1 })).toBeVisible();

        const add = page.getByRole('button', { name: 'Add pay item', exact: true }).first();

        // Adding an item needs an employee and an open pay period; without them there is no button.
        if (await add.isVisible()) {
            await add.click();
            const dialog = page.getByRole('dialog');
            await dialog.getByLabel(/^Amount/).fill('12.345');
            await expect(dialog).toContainText('at most 2 decimal places');
        }

        noErrors();
    });
});

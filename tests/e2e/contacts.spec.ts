import { expect, test } from '@playwright/test';
import { signIn, watchForErrors } from './support/app';
import { workspace } from './support/env';

/*
 * The shared directory of a workspace: companies, and the people who work at them.
 */
test.describe('contacts and companies', () => {
    test('a company and a person in it are added, found again and seen by a colleague', async ({ page, browser }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');

        // The company first, so that the person can be put in it.
        await page.goto(workspace('acme', 'companies'));
        await page.getByRole('button', { name: 'Add company' }).first().click();

        const companyDialog = page.getByRole('dialog', { name: 'Add a company' });

        await companyDialog.getByLabel('Name', { exact: true }).fill('Padma Textiles');
        await companyDialog.getByLabel('Email').fill('hello@padma.example');
        await companyDialog.getByRole('button', { name: 'Add company' }).click();

        await expect(companyDialog).toBeHidden();
        await expect(page.getByRole('cell', { name: 'Padma Textiles' }).first()).toBeVisible();

        await page.goto(workspace('acme', 'contacts'));
        await page.getByRole('button', { name: 'Add contact' }).first().click();

        const contactDialog = page.getByRole('dialog', { name: 'Add a contact' });

        await contactDialog.getByLabel('First name').fill('Rahim');
        await contactDialog.getByLabel('Last name').fill('Chowdhury');
        await contactDialog.getByLabel('Email').fill('Rahim.Chowdhury@Padma.example');
        await contactDialog.getByLabel('Company').selectOption({ label: 'Padma Textiles' });
        await contactDialog.getByRole('button', { name: 'Add contact' }).click();

        await expect(contactDialog).toBeHidden();

        const row = page.getByRole('row', { name: /Rahim Chowdhury/ });

        await expect(row).toBeVisible();
        // Addresses are kept in lower case, whatever was typed.
        await expect(row).toContainText('rahim.chowdhury@padma.example');
        await expect(row).toContainText('Padma Textiles');

        // Found by the name as it is shown, which is in neither column on its own.
        await page.getByPlaceholder('Search by name, email or phone').fill('Rahim Chow');
        await expect(page.getByRole('row', { name: /Rahim Chowdhury/ })).toBeVisible();

        await page.getByPlaceholder('Search by name, email or phone').fill('nobody-by-this-name');
        await expect(page.getByRole('row', { name: /Rahim Chowdhury/ })).toHaveCount(0);

        noErrors();

        // An employee may see every contact of the workspace, and change only their own.
        const colleague = await (await browser.newContext()).newPage();

        await signIn(colleague, 'acme', 'employee@acme.test');
        await colleague.goto(workspace('acme', 'contacts'));

        await expect(colleague.getByRole('row', { name: /Rahim Chowdhury/ })).toBeVisible();

        await colleague.context().close();
    });

    test('another workspace has a directory of its own', async ({ page }) => {
        await signIn(page, 'globex', 'owner@globex.test');
        await page.goto(workspace('globex', 'contacts'));

        await expect(page.getByText('Rahim Chowdhury')).toHaveCount(0);
        await expect(page.getByText('Padma Textiles')).toHaveCount(0);
    });
});

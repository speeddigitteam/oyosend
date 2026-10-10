import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('CRM', () => {
    test('an owner can open CRM and create a lead', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'Contacts', exact: true }).click();
        await page.getByRole('button', { name: 'Add contact' }).first().click();

        const contactDialog = page.getByRole('dialog', { name: 'Add a contact' });

        await contactDialog.getByLabel('First name').fill('CRM');
        await contactDialog.getByLabel('Last name').fill('Prospect');
        await contactDialog.getByRole('button', { name: 'Add contact' }).click();
        await expect(contactDialog).toBeHidden();

        await sidebar(page).getByRole('link', { name: 'CRM leads', exact: true }).click();

        await expect(page.getByRole('heading', { name: 'Leads', level: 1 })).toBeVisible();
        await page.getByRole('button', { name: 'Add lead', exact: true }).first().click();

        const dialog = page.getByRole('dialog', { name: 'Add a lead' });
        await dialog.getByLabel('Title').fill('Website redesign');
        await dialog.getByLabel('Contact').selectOption({ label: 'CRM Prospect' });
        await dialog.getByRole('button', { name: 'Save changes' }).click();

        await expect(page).toHaveURL(/\/crm\/leads\/[^/]+$/);
        await expect(page.getByRole('heading', { name: 'Website redesign', level: 1 })).toBeVisible();

        noErrors();
    });
});

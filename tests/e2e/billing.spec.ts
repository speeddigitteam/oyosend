import { expect, test } from '@playwright/test';
import { signIn, watchForErrors } from './support/app';
import { workspace } from './support/env';

/*
 * The billing page of a workspace. Acme is seeded on a trial of the Business
 * plan; Globex is on the free plan.
 */
test.describe('billing', () => {
    test('the owner sees the plan, what is used of it and the plans on offer', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await page.goto(workspace('acme', 'billing'));

        await expect(page.getByRole('heading', { name: 'Billing', level: 1 })).toBeVisible();
        await expect(page.getByText('On trial until')).toBeVisible();
        await expect(page.getByText('5 of 50')).toBeVisible();

        for (const plan of ['Free', 'Starter', 'Business']) {
            await expect(page.getByRole('heading', { name: plan, exact: true, level: 3 })).toBeVisible();
        }

        await expect(page.getByText('No invoices yet')).toBeVisible();

        noErrors();
    });

    test('changing plan says what will happen first, and does nothing when it is called off', async ({ page }) => {
        await signIn(page, 'acme', 'owner@acme.test');
        await page.goto(workspace('acme', 'billing'));

        await page.getByRole('button', { name: 'Choose the Starter plan' }).click();

        const dialog = page.getByRole('dialog', { name: 'Change to the Starter plan?' });

        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText('per month');

        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();

        await page.reload();
        await expect(page.getByText('The workspace is on this plan.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Choose the Business plan' })).toHaveCount(0);
    });

    test('during the trial a change of plan applies at once', async ({ page }) => {
        await signIn(page, 'acme', 'owner@acme.test');
        await page.goto(workspace('acme', 'billing'));

        await page.getByRole('button', { name: 'Choose the Starter plan' }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Change plan' }).click();

        // Now Starter is the plan the workspace is on, and Business can be chosen again.
        await expect(page.getByRole('button', { name: 'Choose the Business plan' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Choose the Starter plan' })).toHaveCount(0);
        await expect(page.getByText('5 of 10')).toBeVisible();
    });
});

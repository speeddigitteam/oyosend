import { expect, test } from '@playwright/test';
import { sidebar, signIn, watchForErrors } from './support/app';

test.describe('Projects', () => {
    test('an owner can open Projects and create a project', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', 'owner@acme.test');
        await sidebar(page).getByRole('link', { name: 'Projects', exact: true }).click();

        await expect(page.getByRole('heading', { name: 'Projects', level: 1 })).toBeVisible();
        await page.getByRole('button', { name: 'Create project', exact: true }).first().click();

        const dialog = page.getByRole('dialog', { name: 'Create project' });

        await dialog.getByLabel('Name').fill('Warehouse launch');
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();

        await expect(page).toHaveURL(/\/projects\/[^/]+$/);
        await expect(page.getByRole('heading', { name: 'Warehouse launch', level: 1 })).toBeVisible();

        const taskView = page.getByRole('radiogroup', { name: 'Task view' });

        await expect(taskView.getByRole('radio', { name: 'Kanban board' })).toBeChecked();

        await page.getByRole('button', { name: 'Create task', exact: true }).click();
        const taskForm = page.getByRole('dialog', { name: 'Create task' });
        await taskForm.getByLabel('Title').fill('Prepare warehouse release');
        await taskForm.getByRole('button', { name: 'Save', exact: true }).click();

        await page.getByRole('button', { name: /Prepare warehouse release/ }).click();
        const taskDetail = page.getByRole('dialog', { name: 'Prepare warehouse release' });
        await taskDetail.getByRole('button', { name: 'Manage assignees', exact: true }).click();

        const assignments = page.getByRole('dialog', { name: 'Manage assignees' });
        await assignments.getByRole('button', { name: /^Remove / }).click();
        await assignments.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(taskDetail.getByText('Unassigned', { exact: true })).toBeVisible();

        await taskDetail
            .getByRole('contentinfo')
            .getByRole('button', { name: 'Close', exact: true })
            .click();
        await page.getByRole('button', { name: 'Start project', exact: true }).click();
        await expect(page.getByText('Active', { exact: true }).first()).toBeVisible();

        await sidebar(page).getByRole('link', { name: 'Project time', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Time & timesheets', level: 1 })).toBeVisible();
        await page.getByRole('button', { name: 'Log time', exact: true }).click();

        const timeForm = page.getByRole('dialog', { name: 'Log time' });
        const taskOptionsResponse = page.waitForResponse((response) => {
            const url = new URL(response.url());

            return response.request().method() === 'GET' && url.pathname === '/project-task-options';
        });

        await timeForm.getByLabel('Project', { exact: true }).selectOption({ label: 'Warehouse launch' });
        expect((await taskOptionsResponse).ok()).toBe(true);

        const taskSelect = timeForm.getByLabel('Task', { exact: true });

        await taskSelect.selectOption({ label: 'Prepare warehouse release' });
        await expect(taskSelect.locator('option:checked')).toHaveText('Prepare warehouse release');
        await timeForm.getByLabel('Date', { exact: true }).fill('2026-10-11');
        await timeForm.getByLabel('Duration in minutes', { exact: true }).fill('90');
        await timeForm.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(timeForm).toBeHidden();

        const timeRow = page
            .getByRole('table', { name: 'Time entries' })
            .getByRole('row')
            .filter({ hasText: 'Warehouse launch' });

        await expect(timeRow).toContainText('Prepare warehouse release');
        await expect(timeRow).toContainText('1.5 hr');

        noErrors();
    });
});

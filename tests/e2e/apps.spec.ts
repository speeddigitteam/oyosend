import { expect, test, type Page } from '@playwright/test';
import bnCommon from '../../resources/js/Core/i18n/locales/bn/common';
import bnReports from '../../resources/js/Core/i18n/locales/bn/reports';
import bnSupport from '../../resources/js/Core/i18n/locales/bn/support';
import enAutomation from '../../resources/js/Core/i18n/locales/en/automation';
import enCommon from '../../resources/js/Core/i18n/locales/en/common';
import enCommunication from '../../resources/js/Core/i18n/locales/en/communication';
import enEmailMarketing from '../../resources/js/Core/i18n/locales/en/emailMarketing';
import enReports from '../../resources/js/Core/i18n/locales/en/reports';
import enShell from '../../resources/js/Core/i18n/locales/en/shell';
import enSmsMarketing from '../../resources/js/Core/i18n/locales/en/smsMarketing';
import enSocial from '../../resources/js/Core/i18n/locales/en/social';
import enSupport from '../../resources/js/Core/i18n/locales/en/support';
import enWhatsapp from '../../resources/js/Core/i18n/locales/en/whatsapp';
import { sidebar, signIn, watchForErrors } from './support/app';
import { workspace } from './support/env';

/*
 * One smoke flow per optional app, in English, in the Acme workspace (Business plan: every app but AI),
 * then two Bengali flows. Page titles come from the shipped catalogues, so a reworded heading cannot rot a
 * spec. Writes create uniquely named records, so the specs can be re-run on one database.
 */
const OWNER = 'owner@acme.test';
const unique = (label: string): string => `${label} ${Date.now().toString(36)}`;

/** Opens a workspace page and expects its level-one heading, with the browser free of errors. */
async function opens(page: Page, path: string, heading: string): Promise<void> {
    const noErrors = watchForErrors(page);

    await page.goto(workspace('acme', path));
    await expect(page.getByRole('heading', { level: 1, name: heading, exact: true })).toBeVisible();
    await page.waitForLoadState('networkidle');

    noErrors();
}

test.describe('optional apps, in English', () => {
    test('the owner signs in and the dashboard renders its widgets', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);

        await expect(page.getByRole('heading', { level: 1 })).toContainText('Ayesha');
        await page.waitForLoadState('networkidle');

        noErrors();
    });

    test('Social: the posts page loads and a draft can be saved', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);
        await page.goto(workspace('acme', 'social/posts'));
        await expect(page.getByRole('heading', { level: 1, name: enSocial.posts.title, exact: true })).toBeVisible();

        await page.goto(workspace('acme', 'social/posts/create'));
        await expect(page.getByRole('heading', { level: 1, name: enSocial.form.createTitle, exact: true })).toBeVisible();

        const title = unique('Draft');

        await page.getByLabel(new RegExp(`^${enSocial.fields.title}`)).fill(title);
        await page.getByLabel(new RegExp(`^${enSocial.fields.body}`)).first().fill('Hello from the end-to-end suite.');
        await page.getByRole('button', { name: enSocial.form.saveDraft, exact: true }).click();

        await expect(page).toHaveURL(/\/social\/posts\/(?!create)[^/]+$/);
        await expect(page.getByText(title).first()).toBeVisible();

        noErrors();
    });

    test('Support: the ticket list loads and a ticket can be created', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);
        await page.goto(workspace('acme', 'support/tickets'));
        await expect(page.getByRole('heading', { level: 1, name: enSupport.tickets.title, exact: true })).toBeVisible();

        await page.goto(workspace('acme', 'support/tickets/create'));
        await expect(page.getByRole('heading', { level: 1, name: enSupport.create.title, exact: true })).toBeVisible();

        const subject = unique('Printer will not print');

        await page.getByLabel(enSupport.fields.subject).fill(subject);
        // A ticket needs a customer: a contact, or the requester's email.
        await page.getByLabel(new RegExp(`^${enSupport.fields.requesterEmail}`)).fill('printer.customer@example.test');
        await page.getByLabel(enSupport.fields.description).first().fill('The office printer shows a paper jam that is not there.');
        await page.getByRole('button', { name: enSupport.create.submit, exact: true }).click();

        await expect(page).toHaveURL(/\/support\/tickets\/(?!create)[^/]+$/);
        await expect(page.getByText(subject).first()).toBeVisible();

        noErrors();
    });

    test('Automation: a three-step workflow is built and saved', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);
        await page.goto(workspace('acme', 'automation/workflows'));
        await expect(page.getByRole('heading', { level: 1, name: enAutomation.workflows.title, exact: true })).toBeVisible();

        const name = unique('Smoke workflow');

        await page.getByRole('button', { name: enAutomation.workflows.add, exact: true }).first().click();

        const dialog = page.getByRole('dialog');

        await dialog.getByLabel(enAutomation.common.name).fill(name);
        await dialog.getByRole('button', { name: enAutomation.workflows.create.submit, exact: true }).click();
        await expect(page).toHaveURL(/\/automation\/workflows\/[^/]+\/editor$/);

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();

        // A new workflow is a trigger leading to an end. Put a delay between them: three steps in a line.
        await page.getByRole('button', { name: new RegExp(`^${enAutomation.nodes.delay}`) }).click();
        await expect(page.locator('.vue-flow__node')).toHaveCount(3);

        const nodeAt = (index: number) => page.locator('.vue-flow__node').nth(index);
        const next = page.getByLabel(`${enAutomation.handles.next}: ${enAutomation.editor.node.addConnection}`);
        const connectTo = async (label: string): Promise<void> => {
            const value = await next.locator('option', { hasText: label }).first().getAttribute('value');

            await next.selectOption(value ?? '');
        };

        // The new step is selected, so wire it first; then reroute the trigger through it.
        await connectTo(enAutomation.nodes.end);

        await nodeAt(0).click();
        await page.getByRole('button', { name: /^Remove connection to/ }).click();
        await connectTo(enAutomation.nodes.delay);

        await expect(page.locator('.vue-flow__edge')).toHaveCount(2);

        await page.getByRole('button', { name: enAutomation.editor.saveDraft, exact: true }).click();
        await expect(page.getByText(enAutomation.editor.saved, { exact: true }).first()).toBeVisible();

        noErrors();
    });

    test('Reports: a report runs and its CSV downloads', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);
        await page.goto(workspace('acme', 'reports'));
        await expect(page.getByRole('heading', { level: 1, name: enReports.index.title, exact: true })).toBeVisible();

        await page.getByTestId('catalogue').getByRole('link').first().click();
        await expect(page).toHaveURL(/\/reports\/run\/[^/?]+/);

        const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export').click()]);

        expect(download.suggestedFilename()).toMatch(/\.csv$/);

        noErrors();
    });

    test('Communication: the messages page loads', async ({ page }) => {
        await signIn(page, 'acme', OWNER);
        await opens(page, 'communication/messages', enCommunication.messages.title);
    });

    test('Email marketing: the campaigns page loads', async ({ page }) => {
        await signIn(page, 'acme', OWNER);
        await opens(page, 'email-marketing/campaigns', enEmailMarketing.campaigns.title);
    });

    test('SMS marketing: the campaigns page loads', async ({ page }) => {
        await signIn(page, 'acme', OWNER);
        await opens(page, 'sms-marketing/campaigns', enSmsMarketing.campaigns.title);
    });

    test('WhatsApp marketing: the campaigns page loads', async ({ page }) => {
        await signIn(page, 'acme', OWNER);
        await opens(page, 'whatsapp/campaigns', enWhatsapp.campaigns.title);
    });

    test('AI: not part of the Business plan, so the workspace does not offer it', async ({ page }) => {
        await signIn(page, 'acme', OWNER);

        const response = await page.goto(workspace('acme', 'ai/assistant'));

        expect(response?.status()).toBe(404);
        await expect(sidebar(page).getByRole('link', { name: 'AI assistant' })).toHaveCount(0);
    });

    test('the owner signs out and is returned to the sign-in page', async ({ page }) => {
        await signIn(page, 'acme', OWNER);

        await page.getByRole('button', { name: enShell.account.menu }).click();
        await page.getByRole('menuitem', { name: enShell.account.signOut }).click();

        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator('input[type="password"]')).toBeVisible();

        // The session is gone: a workspace page sends the visitor back to sign in.
        await page.goto(workspace('acme', 'support/tickets'));
        await expect(page).toHaveURL(/\/login/);
    });
});

const BENGALI = /[ঀ-৿]/;

test.describe('Bengali', () => {
    test('a Bengali workspace signs in and shows its dashboard in Bengali', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'globex', 'owner@globex.test');

        await expect(page.locator('html')).toHaveAttribute('lang', /^bn/);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(BENGALI);
        await page.waitForLoadState('networkidle');

        noErrors();
    });

    test('a person who switches to Bengali finds the app pages translated, and switches back', async ({ page }) => {
        const noErrors = watchForErrors(page);

        await signIn(page, 'acme', OWNER);

        const setLanguage = async (code: 'bn' | 'en', save: string): Promise<void> => {
            await page.goto(workspace('acme', 'settings/profile'));
            await page.locator('select[name="locale"]').selectOption(code);
            await page.getByRole('button', { name: save, exact: true }).click();
            await page.waitForLoadState('networkidle');
        };

        await setLanguage('bn', enCommon.actions.save);

        try {
            await page.goto(workspace('acme', 'support/tickets'));
            await expect(page.getByRole('heading', { level: 1, name: bnSupport.tickets.title, exact: true })).toBeVisible();

            await page.goto(workspace('acme', 'reports'));
            await expect(page.getByRole('heading', { level: 1, name: bnReports.index.title, exact: true })).toBeVisible();
        } finally {
            await setLanguage('en', bnCommon.actions.save);
        }

        await page.goto(workspace('acme', 'support/tickets'));
        await expect(page.getByRole('heading', { level: 1, name: enSupport.tickets.title, exact: true })).toBeVisible();

        noErrors();
    });
});

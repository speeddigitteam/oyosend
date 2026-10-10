import { createHmac } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Page } from '@playwright/test';
import { PASSWORD, workspace } from './env';

/**
 * Sign in to a workspace on its own host and wait until the app has let the
 * person in.
 */
export async function signIn(page: Page, slug: string, email: string, password: string = PASSWORD): Promise<void> {
    await page.goto(workspace(slug, 'login'));
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(password);
    // By its type, not its label: a workspace's sign-in page is in that workspace's language.
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/** The sidebar: where a person sees which parts of the workspace are theirs. Its name is translated too. */
export function sidebar(page: Page, name: string = 'Primary') {
    return page.getByRole('navigation', { name });
}

/**
 * Fails the test when the browser reports an error: a script that threw, a
 * request that failed, or something the Content Security Policy would have
 * refused (it runs in report-only mode, and reports to the console).
 */
export function watchForErrors(page: Page): () => void {
    const problems: string[] = [];

    page.on('pageerror', (error) => problems.push(`script error: ${error.message}`));
    page.on('console', (message) => {
        // 409 is how the server tells the app to leave for another address (a workspace's
        // own host, the panel): part of the protocol, though the browser logs it as an error.
        if (message.type() === 'error' && !message.text().includes('status of 409')) {
            problems.push(`console: ${message.text()}`);
        }
    });

    return () => expect(problems, 'the browser reported errors').toEqual([]);
}

/*
|--------------------------------------------------------------------------
| Mail
|--------------------------------------------------------------------------
| The app writes mail to its log (MAIL_MAILER=log). A test notes where the
| log ends before it does something, then reads the link out of what was
| written after that.
*/

const LOGS = join(process.cwd(), 'storage', 'logs');

function mailLog(): string | null {
    if (!existsSync(LOGS)) {
        return null;
    }

    const files = readdirSync(LOGS)
        .filter((name) => /^laravel-\d{4}-\d{2}-\d{2}\.log$/.test(name))
        .map((name) => join(LOGS, name))
        .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);

    return files[0] ?? null;
}

/** Where the mail log ends now. */
export function mailMark(): number {
    const file = mailLog();

    return file === null ? 0 : statSync(file).size;
}

/**
 * The first link matching `pattern` in mail written since `mark`. Polls: the
 * mail is written by the request the browser has just made.
 */
export async function linkFromMail(pattern: RegExp, mark: number): Promise<string> {
    for (let attempt = 0; attempt < 40; attempt++) {
        const file = mailLog();

        if (file !== null) {
            const size = statSync(file).size;
            // A new day's file starts from nothing.
            const written = readFileSync(file).subarray(size >= mark ? mark : 0).toString('utf8');
            // Mail bodies are quoted-printable: soft line breaks, and "=" written as "=3D".
            const text = written.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
            const match = text.match(pattern);

            if (match) {
                return match[0];
            }
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error(`No mail with a link like ${pattern} was written.`);
}

/*
|--------------------------------------------------------------------------
| Two-step verification
|--------------------------------------------------------------------------
*/

/** The code an authenticator app shows for a secret (RFC 6238: SHA-1, 30 seconds, 6 digits). */
export function totp(secret: string, at: number = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const character of secret.replace(/[\s=]/g, '').toUpperCase()) {
        const value = alphabet.indexOf(character);

        if (value < 0) {
            throw new Error(`Not a base32 secret: ${secret}`);
        }

        bits += value.toString(2).padStart(5, '0');
    }

    const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);

    counter.writeBigUInt64BE(BigInt(Math.floor(at / 30_000)));

    const digest = createHmac('sha1', key).update(counter).digest();
    const offset = digest[digest.length - 1]! & 0x0f;

    return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
}

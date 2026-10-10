import { execFileSync } from 'node:child_process';
import { appEnv, DATABASE } from './support/env';

/*
 * Rebuilds the end-to-end database and seeds the demo workspaces.
 *
 * `migrate:fresh` empties a database. It is only ever pointed at the one named
 * in `support/env.ts`, and only after the application itself has confirmed
 * that this is the database it is connected to.
 */
export default function globalSetup(): void {
    if (!/_testing$/.test(DATABASE)) {
        throw new Error(`Refusing to rebuild [${DATABASE}]: the end-to-end database must be named *_testing.`);
    }

    const artisan = (...args: string[]): string =>
        execFileSync('php', ['artisan', ...args], {
            env: { ...process.env, ...appEnv },
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'inherit'],
        });

    // Creates the database when it does not exist yet.
    artisan('migrate', '--force');

    const connected = artisan('tinker', '--execute=echo DB::connection()->getDatabaseName();').trim();

    if (connected !== DATABASE) {
        throw new Error(`Refusing to rebuild: the application is connected to [${connected}], not [${DATABASE}].`);
    }

    artisan('migrate:fresh', '--seed', '--force');
}

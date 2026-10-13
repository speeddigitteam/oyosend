/*
 * The application the end-to-end tests run against: its own port and its own
 * database, so neither a running `composer dev` nor the Pest suite is disturbed.
 *
 * Everything the app keeps (sessions, cache, queue) is in that database, and
 * `global-setup.ts` rebuilds it for every run.
 */
/** Override with E2E_PORT / E2E_DATABASE to run beside another run (never share a database). */
export const PORT = Number(process.env.E2E_PORT ?? 8010);

/** Must end in `_testing`: the setup wipes it and refuses any other name. */
export const DATABASE = process.env.E2E_DATABASE ?? 'oyosend_e2e_testing';

export const appEnv: Record<string, string> = {
    // The demo seeder only runs locally; these tests use its workspaces and logins.
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_DOMAIN: 'localhost',
    APP_URL: `http://localhost:${PORT}`,
    DB_DATABASE: DATABASE,
    // A developer's .env may switch this off; the admin flows are about the enforced behaviour.
    PLATFORM_REQUIRE_2FA: 'true',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    // Mail is written at once, into the log the tests read links from.
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
    MAIL_LOG_CHANNEL: 'daily',
    LOG_CHANNEL: 'daily',
};

export const central = (path = ''): string => `http://localhost:${PORT}/${path}`;

export const workspace = (slug: string, path = ''): string => `http://${slug}.localhost:${PORT}/${path}`;

export const admin = (path = ''): string => `http://admin.localhost:${PORT}/${path}`;

/** Every seeded account uses this password (database/seeders/DemoSeeder.php). */
export const PASSWORD = 'password';

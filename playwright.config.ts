import { defineConfig } from '@playwright/test';
import { appEnv, PORT } from './tests/e2e/support/env';

/*
 * End-to-end tests: a real browser against a real server and database.
 *
 *   npm run test:e2e
 *
 * Needs PHP and MySQL as for local development, the built assets
 * (`npm run build`) and Google Chrome. The app is served on its own port with
 * its own database (tests/e2e/support/env.ts), rebuilt for every run.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/*.spec.ts',
    globalSetup: './tests/e2e/global-setup.ts',
    outputDir: 'storage/framework/testing/e2e',

    // PHP's built-in server answers one request at a time, and the specs share one database.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],

    use: {
        // The Chrome that is installed, so nothing has to be downloaded.
        channel: 'chrome',
        baseURL: `http://localhost:${PORT}`,
        locale: 'en-GB',
        timezoneId: 'Asia/Dhaka',
        viewport: { width: 1366, height: 860 },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },

    webServer: {
        // What `php artisan serve` runs, started directly so that the environment below reaches it.
        command: `php -S 127.0.0.1:${PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        env: appEnv,
        // A static file: it answers before the database has been built.
        url: `http://127.0.0.1:${PORT}/robots.txt`,
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'ignore',
        timeout: 30_000,
    },
});

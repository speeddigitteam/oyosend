<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Every test runs on Tests\TestCase, which carries the workspace helpers
| from tests/Support (createTenant, addMember, actingAsMember, tenantUrl,
| assertTenantIsolated, ...). See docs/TESTING.md.
|
| Feature tests get a migrated `oyosend_testing` database and run inside a
| transaction that is rolled back afterwards. Unit tests boot the application
| for configuration and the container, but never touch the database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

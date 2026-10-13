<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| CLAUDE.md: every tenant-owned table has an indexed tenant_id, and every
| unique index on it starts with tenant_id. This reads the migrated schema, so
| it covers every module's tables, including ones added later. The primary key
| (a ULID) is exempt from the unique-index rule.
*/

/**
 * Unique indexes that deliberately do not lead with tenant_id. Each is a value
 * that has to be unique platform-wide (or a 1:1 key that is already a globally
 * unique ULID), and several are looked up before any tenant is known. Add to
 * this list only with a reason; a new tenant-owned table gets none.
 */
const TENANT_INDEX_EXEMPT_UNIQUE = [
    'app_entitlements.app_entitlements_license_id_unique' => 'one entitlement per licence; licence ids are globally unique ULIDs',
    'impersonations.impersonations_token_hash_unique' => 'one-time token is redeemed on the central host before a tenant is known',
    'invitations.invitations_token_hash_unique' => 'invitation link is resolved from the token alone',
    'invoices.invoices_number_unique' => 'platform invoice numbers are one sequence for the issuer, not per workspace',
    'payments.payments_gateway_gateway_reference_unique' => 'a gateway reference identifies one payment platform-wide (callback lookup)',
    'payments.payments_reference_unique' => 'our payment reference is global; the callback finds the tenant from it',
    'tenant_domains.tenant_domains_domain_unique' => 'a hostname maps to exactly one tenant',
    'workspace_registrations.workspace_registrations_token_hash_unique' => 'confirmation link is resolved from the token alone, before the workspace exists',
];

/** @return array<string, array<string, list<string>>> table => index name => ordered columns */
function schemaIndexes(): array
{
    $database = DB::connection()->getDatabaseName();

    $tenantTables = collect(DB::select(
        'select table_name as t from information_schema.columns where table_schema = ? and column_name = ?',
        [$database, 'tenant_id'],
    ))->map(static fn (object $row): string => strtolower((string) $row->t))->all();

    $tables = [];

    $rows = DB::select(
        'select table_name as t, index_name as i, non_unique as nu, column_name as c
         from information_schema.statistics where table_schema = ?
         order by table_name, index_name, seq_in_index',
        [$database],
    );

    foreach ($rows as $row) {
        $table = strtolower((string) $row->t);

        if (! in_array($table, $tenantTables, true)) {
            continue;
        }

        $key = ((int) $row->nu === 0 ? 'U:' : 'I:').$row->i;
        $tables[$table][$key][] = (string) $row->c;
    }

    // Tenant tables without any index at all still need to be listed.
    foreach ($tenantTables as $table) {
        $tables[$table] ??= [];
    }

    ksort($tables);

    return $tables;
}

it('finds the tenant-owned tables of every module', function () {
    expect(count(schemaIndexes()))->toBeGreaterThan(60);
});

it('gives every tenant-owned table an index that starts with tenant_id', function () {
    $violations = [];

    foreach (schemaIndexes() as $table => $indexes) {
        $ok = collect($indexes)->contains(static fn (array $columns): bool => $columns[0] === 'tenant_id');

        if (! $ok) {
            $violations[] = $table;
        }
    }

    expect($violations)->toBe([]);
});

it('starts every unique index of a tenant-owned table with tenant_id', function () {
    $violations = [];

    foreach (schemaIndexes() as $table => $indexes) {
        foreach ($indexes as $key => $columns) {
            if (! str_starts_with($key, 'U:') || $key === 'U:PRIMARY') {
                continue;
            }

            $name = substr($key, 2);

            if ($columns[0] !== 'tenant_id' && ! array_key_exists("{$table}.{$name}", TENANT_INDEX_EXEMPT_UNIQUE)) {
                $violations[] = "{$table}.{$name} (".implode(', ', $columns).')';
            }
        }
    }

    expect($violations)->toBe([]);
});

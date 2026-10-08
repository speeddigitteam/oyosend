<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Models\Permission;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Makes the `permissions` table match what the registered modules declare.
 *
 * Run on every deploy (`php artisan permissions:sync`). It is idempotent.
 */
final class PermissionSynchronizer
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array{created: list<string>, stale: list<string>, pruned: list<string>}
     */
    public function sync(bool $prune = false): array
    {
        $declared = $this->modules->permissions();
        $existing = Permission::query()->where('guard_name', 'web')->pluck('module', 'name');
        $now = Carbon::now();

        $created = [];
        $rows = [];

        foreach ($declared as $name => $definition) {
            if (! $existing->has($name)) {
                $created[] = $name;
            }

            $rows[] = [
                'name' => $name,
                'guard_name' => 'web',
                'module' => $definition->module(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $stale = $existing->keys()->diff(array_keys($declared))->values()->all();

        DB::transaction(function () use ($rows, $stale, $prune): void {
            if ($rows !== []) {
                Permission::query()->upsert($rows, ['name', 'guard_name'], ['module', 'updated_at']);
            }

            // Removing a permission also removes every grant of it, in every
            // tenant, so it only happens when asked for explicitly.
            if ($prune && $stale !== []) {
                Permission::query()->where('guard_name', 'web')->whereIn('name', $stale)->delete();
            }
        });

        return [
            'created' => $created,
            'stale' => $stale,
            'pruned' => $prune ? $stale : [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Access\Console;

use App\Core\Access\PermissionSynchronizer;
use Illuminate\Console\Command;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync {--prune : Delete permissions no module declares any more, with all their grants}';

    protected $description = 'Sync the permission catalogue with the permissions declared by registered modules';

    public function handle(PermissionSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync((bool) $this->option('prune'));

        $this->components->info(sprintf('%d permission(s) added.', count($result['created'])));

        foreach ($result['created'] as $name) {
            $this->components->twoColumnDetail($name, '<fg=green>added</>');
        }

        foreach ($result['pruned'] as $name) {
            $this->components->twoColumnDetail($name, '<fg=red>removed</>');
        }

        $unpruned = array_diff($result['stale'], $result['pruned']);

        if ($unpruned !== []) {
            $this->components->warn(sprintf(
                '%d permission(s) are no longer declared by any module. Re-run with --prune to remove them.',
                count($unpruned),
            ));

            foreach ($unpruned as $name) {
                $this->components->twoColumnDetail($name, '<fg=yellow>stale</>');
            }
        }

        return self::SUCCESS;
    }
}

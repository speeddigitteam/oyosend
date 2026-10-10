<?php

declare(strict_types=1);

namespace App\Core\AppStore\Console;

use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/** Dispatches due first-party module jobs only for tenants entitled to that module. */
final class RunModuleSchedulesCommand extends Command
{
    protected $signature = 'apps:run-scheduled {--tenant= : Only this workspace (its address, e.g. "acme")}';

    protected $description = 'Dispatch due, entitlement-gated jobs declared by first-party app manifests';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
        private readonly CacheRepository $centralCache,
        private readonly Dispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifests = array_values(array_filter(
            $this->modules->all(),
            static fn (ModuleManifest $manifest): bool => ! $manifest->core && $manifest->scheduledJobs !== [],
        ));

        if ($manifests === []) {
            $this->components->info('No app schedules are registered.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->when($this->option('tenant'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->chunkById(100, function ($tenants) use ($manifests, &$dispatched, &$failed): void {
                foreach ($tenants as $tenant) {
                    try {
                        $this->context->run($tenant, function () use ($tenant, $manifests, &$dispatched, &$failed): void {
                            $now = CarbonImmutable::now($tenant->timezone);

                            foreach ($manifests as $manifest) {
                                if (! $this->entitlements->allows($tenant, $manifest->key)) {
                                    continue;
                                }

                                foreach ($manifest->scheduledJobs as $definition) {
                                    if (! $definition->frequency->isDue($now)) {
                                        continue;
                                    }

                                    $cacheKey = implode(':', [
                                        'app-schedule',
                                        $tenant->getTenantKey(),
                                        $manifest->key,
                                        $definition->key,
                                        $definition->frequency->slot($now),
                                    ]);

                                    if (! $this->centralCache->add($cacheKey, true, now()->addDays(2))) {
                                        continue;
                                    }

                                    try {
                                        $job = new ($definition->job);
                                        $this->dispatcher->dispatch(new RunScheduledAppJob(
                                            $manifest->key,
                                            $definition->key,
                                            $job,
                                        ));
                                        $dispatched++;
                                    } catch (Throwable $exception) {
                                        $this->centralCache->forget($cacheKey);
                                        $failed++;
                                        report($exception);
                                        $this->components->error("{$tenant->slug}/{$definition->key}: {$exception->getMessage()}");
                                    }
                                }
                            }
                        });
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                        $this->components->error("{$tenant->slug}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Dispatched {$dispatched} app job(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

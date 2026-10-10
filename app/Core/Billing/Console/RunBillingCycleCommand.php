<?php

declare(strict_types=1);

namespace App\Core\Billing\Console;

use App\Core\Billing\BillingCycle;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Moves every subscription along in time: issues the invoices that are due,
 * marks what is overdue, ends what ran out. Scheduled hourly; safe to run by
 * hand and to run twice.
 */
final class RunBillingCycleCommand extends Command
{
    protected $signature = 'billing:run {--tenant= : Only this workspace (its address, e.g. "acme")}';

    protected $description = 'Issue due invoices and apply overdue, expiry and cancellation to subscriptions';

    public function handle(BillingCycle $cycle, TenantContext $context): int
    {
        $failed = 0;
        $processed = 0;

        // Each workspace is handled inside its own context, so nothing here reads across tenants.
        Tenant::query()
            ->when($this->option('tenant'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->whereHas('subscription', fn ($query) => $query->whereIn('status', [
                SubscriptionStatus::Trialing,
                SubscriptionStatus::Active,
                SubscriptionStatus::PastDue,
            ]))
            ->chunkById(100, function ($tenants) use ($cycle, $context, &$failed, &$processed): void {
                foreach ($tenants as $tenant) {
                    try {
                        $context->run($tenant, function () use ($cycle): void {
                            $cycle->run();
                        });

                        $processed++;
                    } catch (Throwable $exception) {
                        // One workspace's failure must not stop the others.
                        $failed++;

                        report($exception);

                        $this->components->error("{$tenant->slug}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Billing cycle run for {$processed} workspace(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

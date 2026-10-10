<?php

declare(strict_types=1);

namespace App\Core\AppStore;

use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Advances separately billed app licences through renewal, grace and expiry. */
final class AppBillingCycle
{
    public function __construct(
        private readonly AppStoreManager $apps,
        private readonly InvoiceIssuer $invoices,
        private readonly PlatformSettings $settings,
        private readonly ModuleRegistry $modules,
    ) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            SubscriptionItem::query()
                ->with(['app', 'installation'])
                ->whereIn('status', [
                    SubscriptionItemStatus::Trialing,
                    SubscriptionItemStatus::Active,
                    SubscriptionItemStatus::PastDue,
                ])
                ->lockForUpdate()
                ->get()
                ->filter(fn (SubscriptionItem $item): bool => $this->modules->has($item->app->module_key))
                ->each(fn (SubscriptionItem $item) => $this->advance($item, Carbon::now()));
        });
    }

    private function advance(SubscriptionItem $item, CarbonInterface $now): void
    {
        if ($item->status === SubscriptionItemStatus::PastDue) {
            if ($item->cancel_at_period_end) {
                $this->apps->expire($item, canceled: true);

                return;
            }

            if ($item->grace_ends_at === null || $item->grace_ends_at->lessThanOrEqualTo($now)) {
                $this->apps->expire($item);

                return;
            }

            $this->issueIfPossible($item, $item->current_period_end ?? $now);

            return;
        }

        $boundary = $item->status === SubscriptionItemStatus::Trialing
            ? $item->trial_ends_at
            : $item->current_period_end;

        // One-time licences have no period once paid.
        if ($boundary === null) {
            return;
        }

        if ($item->cancel_at_period_end) {
            if ($boundary->lessThanOrEqualTo($now)) {
                $this->apps->expire($item, canceled: true);
            }

            return;
        }

        if ($this->dueForInvoice($boundary, $now)) {
            $this->issueIfPossible($item, $boundary);
        }

        if ($boundary->lessThanOrEqualTo($now)) {
            $this->apps->markPastDue($item, $boundary);
        }
    }

    private function issueIfPossible(SubscriptionItem $item, CarbonInterface $boundary): void
    {
        if ($this->invoices->hasOpenApp($item)) {
            return;
        }

        $this->invoices->issueApp(
            $item,
            $item->app,
            InvoiceKind::AppRenewal,
            $boundary,
            $boundary,
        );
    }

    private function dueForInvoice(CarbonInterface $boundary, CarbonInterface $now): bool
    {
        return Carbon::instance($boundary)
            ->subDays($this->settings->invoiceLeadDays())
            ->lessThanOrEqualTo($now);
    }
}

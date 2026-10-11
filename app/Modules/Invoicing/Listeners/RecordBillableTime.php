<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Listeners;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Events\DomainEvent;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Models\BillableTime;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Remembers approved, billable time announced by Projects (`projects.time_entry.approved`)
 * so it can later be invoiced. Reads the documented payload only: no Projects model.
 */
final class RecordBillableTime
{
    public const string EVENT = 'projects.time_entry.approved';

    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly TenantContext $context,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if ($event::name() !== self::EVENT || ! $this->context->check() || $this->context->id() !== $event->tenantId()) {
            return;
        }

        if (! $this->entitlements->allows($this->context->require(), 'invoicing')) {
            return;
        }

        $data = $event->payload();
        $minutes = (int) ($data['minutes'] ?? 0);
        $rate = (int) ($data['billable_rate_amount'] ?? 0);
        $entryId = (string) ($data['time_entry_id'] ?? '');

        if (($data['is_billable'] ?? false) !== true || $minutes < 1 || $rate < 1 || $entryId === '') {
            return;
        }

        try {
            BillableTime::query()->firstOrCreate(['time_entry_id' => $entryId], [
                'project_id' => (string) $data['project_id'],
                'membership_id' => isset($data['membership_id']) ? (string) $data['membership_id'] : null,
                'minutes' => $minutes,
                'rate_amount' => $rate,
                'currency' => strtoupper((string) ($data['currency'] ?? '')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same approval was delivered twice at once.
        }
    }
}

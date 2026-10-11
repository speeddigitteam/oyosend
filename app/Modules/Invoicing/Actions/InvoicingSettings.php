<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Models\InvoicingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace invoicing settings and the workspace calendar day. */
final class InvoicingSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The row for the active workspace; created with defaults on first use. */
    public function current(): InvoicingSetting
    {
        $existing = InvoicingSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return InvoicingSetting::query()->create([])->refresh();
        } catch (UniqueConstraintViolationException) {
            return InvoicingSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): InvoicingSetting
    {
        return DB::transaction(function () use ($attributes): InvoicingSetting {
            $settings = $this->current();
            $settings->fill($attributes)->save();
            $this->audit->record('invoicing.settings.saved', $settings, ['invoice_prefix' => $settings->invoice_prefix]);

            return $settings;
        });
    }

    /** Today's date in the workspace timezone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->require()->timezone)->startOfDay();
    }
}

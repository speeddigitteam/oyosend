<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace settings of the Support app and the workspace clock. */
final class SupportSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): Setting
    {
        $existing = Setting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Setting::query()->create([
                'ticket_prefix' => 'TKT-',
                'number_padding' => 5,
                'default_priority' => TicketPriority::Normal,
                'csat_enabled' => true,
                'csat_expiry_days' => 14,
                'csat_send_email' => true,
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return Setting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): Setting
    {
        return DB::transaction(function () use ($attributes): Setting {
            $settings = $this->current();
            $settings->fill($attributes)->save();
            $this->audit->record('support.settings.saved', $settings, [
                'ticket_prefix' => $settings->ticket_prefix,
                'csat_enabled' => $settings->csat_enabled,
                'auto_close_days' => $settings->auto_close_days,
            ]);

            return $settings;
        });
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    public function workspaceName(): string
    {
        return $this->context->require()->name;
    }
}

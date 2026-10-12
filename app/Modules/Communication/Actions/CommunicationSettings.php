<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Communication\Models\CommunicationSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace communication settings: sender identity, quiet hours and the marketing footer. */
final class CommunicationSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The row for the active workspace; created with defaults on first use. */
    public function current(): CommunicationSetting
    {
        $existing = CommunicationSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return CommunicationSetting::query()->create([])->refresh();
        } catch (UniqueConstraintViolationException) {
            return CommunicationSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): CommunicationSetting
    {
        return DB::transaction(function () use ($attributes): CommunicationSetting {
            $settings = $this->current();
            $settings->fill($attributes)->save();
            $this->audit->record('communication.settings.saved', $settings, ['quiet_hours_enabled' => $settings->quiet_hours_enabled]);

            return $settings;
        });
    }
}

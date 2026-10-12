<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace settings of the app and the workspace's time zone. */
final class EmailMarketingSettings
{
    public function __construct(private readonly TenantContext $context) {}

    /** The row of the active workspace, created with defaults on first use. */
    public function current(): Setting
    {
        $existing = Setting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Setting::query()->create(['send_per_minute' => (int) config('email_marketing.send_per_minute_default', 60)])->refresh();
        } catch (UniqueConstraintViolationException) {
            return Setting::query()->firstOrFail();
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(array $attributes): Setting
    {
        return DB::transaction(function () use ($attributes): Setting {
            $setting = $this->current();
            $setting->fill($attributes)->save();

            return $setting;
        });
    }

    /** What one runner pass may hand over for this workspace, never above the platform ceiling. */
    public function sendPerMinute(): int
    {
        return max(1, min((int) config('email_marketing.send_per_minute_ceiling', 600), $this->current()->send_per_minute));
    }

    public function hasFooterAddress(): bool
    {
        return trim((string) $this->current()->footer_address) !== '';
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    /** Parse a workspace-local date and time (from a form) into UTC. */
    public function toUtc(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $this->timezone())->utc();
    }
}

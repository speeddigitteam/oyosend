<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\MetaAds\Models\MetaAdsSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace Meta Ads settings, created with safe defaults on first use (management off). */
final class MetaAdsSettings
{
    public function __construct(private readonly TenantContext $context) {}

    public function current(): MetaAdsSetting
    {
        $existing = MetaAdsSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return MetaAdsSetting::query()->create([])->refresh();
        } catch (UniqueConstraintViolationException) {
            return MetaAdsSetting::query()->firstOrFail();
        }
    }

    /** @param array{management_enabled?: bool, lookback_days?: int} $attributes */
    public function update(array $attributes): MetaAdsSetting
    {
        return DB::transaction(function () use ($attributes): MetaAdsSetting {
            $settings = $this->current();

            if (isset($attributes['lookback_days'])) {
                $attributes['lookback_days'] = $this->clampLookback((int) $attributes['lookback_days']);
            }

            $settings->fill($attributes)->save();

            return $settings;
        });
    }

    public function lookbackDays(): int
    {
        return $this->clampLookback($this->current()->lookback_days);
    }

    public function managementEnabled(): bool
    {
        return $this->current()->management_enabled && (bool) config('meta_ads.request_management_scope', true);
    }

    /** The workspace's own currency code. */
    public function workspaceCurrency(): string
    {
        return strtoupper($this->context->require()->currency);
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    private function clampLookback(int $days): int
    {
        return max(
            (int) config('meta_ads.sync.min_lookback_days', 7),
            min((int) config('meta_ads.sync.max_lookback_days', 90), $days),
        );
    }
}

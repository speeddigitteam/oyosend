<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Workspace SMS Marketing settings and the workspace clock. */
final class SmsSettings
{
    /** What recipients are told when the workspace has not set its own wording. English keeps messages GSM-7. */
    public const string DEFAULT_OPT_OUT = 'Opt out:';

    public function __construct(private readonly TenantContext $context) {}

    /** The row for the active workspace; created with the configured defaults on first use. */
    public function current(): Setting
    {
        $existing = Setting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Setting::query()->create([
                'daily_cap' => (int) config('sms_marketing.defaults.daily_cap', 1000),
                'throttle_per_minute' => (int) config('sms_marketing.defaults.throttle_per_minute', 60),
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return Setting::query()->firstOrFail();
        }
    }

    /**
     * @param  array{daily_cap: int, throttle_per_minute: int, opt_out_wording?: string|null, segment_cost_amount?: int|null, default_sender_id?: string|null}  $attributes
     */
    public function update(array $attributes): Setting
    {
        return DB::transaction(function () use ($attributes): Setting {
            $settings = $this->current();
            $senderId = $attributes['default_sender_id'] ?? null;

            if ($senderId !== null && ! SenderId::query()->whereKey($senderId)->exists()) {
                throw ValidationException::withMessages(['default_sender_id' => __('Choose one of the workspace sender ids.')]);
            }

            $cost = $attributes['segment_cost_amount'] ?? null;
            $wording = isset($attributes['opt_out_wording']) ? trim((string) $attributes['opt_out_wording']) : '';

            $settings->fill([
                'daily_cap' => $attributes['daily_cap'],
                'throttle_per_minute' => $attributes['throttle_per_minute'],
                'opt_out_wording' => $wording === '' ? null : $wording,
                'segment_cost_amount' => $cost,
                'currency' => $cost === null ? null : $this->context->require()->currency,
                'default_sender_id' => $senderId,
            ])->save();

            return $settings;
        });
    }

    public function optOutWording(): string
    {
        return $this->current()->opt_out_wording ?? self::DEFAULT_OPT_OUT;
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    public function currency(): string
    {
        return $this->context->require()->currency;
    }

    /** Parse a workspace-local date and time into UTC. */
    public function toUtc(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $this->timezone())->utc();
    }

    /** The start of the workspace's current day as a UTC instant (the daily cap resets there). */
    public function dayStartUtc(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->timezone())->startOfDay()->utc();
    }
}

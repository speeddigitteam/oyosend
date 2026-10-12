<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Models\SocialSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace social settings and the workspace calendar. */
final class SocialSettings
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /** The row for the active workspace; created with defaults on first use. */
    public function current(): SocialSetting
    {
        $existing = SocialSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return SocialSetting::query()->create([])->refresh();
        } catch (UniqueConstraintViolationException) {
            return SocialSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): SocialSetting
    {
        return DB::transaction(function () use ($attributes): SocialSetting {
            $settings = $this->current();
            $settings->fill($attributes)->save();

            return $settings;
        });
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    /** Parse a workspace-local date and time into UTC. */
    public function toUtc(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $this->timezone())->utc();
    }
}

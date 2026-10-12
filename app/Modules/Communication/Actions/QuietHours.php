<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The workspace's quiet hours, in the workspace timezone. They hold back non-transactional messages only.
 */
final class QuietHours
{
    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * When the quiet window that contains `$now` ends, or null when `$now` is not inside one.
     */
    public function endsAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $settings = $this->settings->current();

        if (! $settings->quiet_hours_enabled || $settings->quiet_hours_start === $settings->quiet_hours_end) {
            return null;
        }

        $zone = $this->context->require()->timezone;
        $local = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($zone);
        $start = $this->at($local, $settings->quiet_hours_start);
        $end = $this->at($local, $settings->quiet_hours_end);

        if ($start < $end) {
            return $local >= $start && $local < $end ? $end->setTimezone('UTC') : null;
        }

        // The window crosses midnight: [start, midnight) and [midnight, end).
        if ($local >= $start) {
            return $end->addDay()->setTimezone('UTC');
        }

        return $local < $end ? $end->setTimezone('UTC') : null;
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);

        return $day->setTime($hour, $minute);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Contracts;

use Carbon\CarbonImmutable;

/** One raw punch reported by an {@see AttendanceDevice}, identified by the employee number printed on the badge. */
final readonly class DevicePunch
{
    public function __construct(
        public string $employeeNumber,
        public CarbonImmutable $punchedAt,
        /** `in`, `out` or `unknown` (the device could not tell). */
        public string $direction,
        /** The device's own id for the event, for de-duplication. */
        public string $reference,
    ) {}
}

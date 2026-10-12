<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Contracts;

use Carbon\CarbonImmutable;

/**
 * The seam for a physical attendance device (fingerprint, RFID, face terminal).
 *
 * **Interface only.** No implementation ships, nothing binds it, and there is no route or
 * job that calls it: HRM does not pretend a device is connected. A future integration
 * implements this contract, is registered by its own module, and hands punches to
 * `AttendanceManager::recordInstants()` with `AttendanceSource::Device` (device time is
 * never trusted as-is: the integration must authenticate the device and reject skew).
 */
interface AttendanceDevice
{
    /** Stable identifier of the device or vendor integration, e.g. `zkteco`. */
    public function key(): string;

    /**
     * Punches recorded since an instant, oldest first.
     *
     * @return iterable<DevicePunch>
     */
    public function punchesSince(CarbonImmutable $since): iterable;
}

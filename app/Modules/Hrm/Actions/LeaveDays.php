<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Models\LeaveRequest;

/** Which dates an employee is on approved leave, and how much of each. */
final class LeaveDays
{
    /**
     * Approved leave units per local date inside the range (0 is left out).
     *
     * @return array<string, int>
     */
    public function approved(string $employeeId, string $from, string $to): array
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', LeaveStatus::Approved)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->get(['id', 'days']);

        $units = [];

        foreach ($requests as $request) {
            foreach ($request->days as $date => $value) {
                if ($date >= $from && $date <= $to) {
                    $units[$date] = min(2, ($units[$date] ?? 0) + (int) $value);
                }
            }
        }

        return $units;
    }
}

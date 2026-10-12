<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Enums;

/**
 * Employment lifecycle. `terminated` is final for the record's history but a
 * person can be reinstated (an audited, history-recorded transition).
 */
enum EmployeeStatus: string
{
    case Probation = 'probation';
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /** Expected to attend work (attendance days are generated for them). */
    public function isWorking(): bool
    {
        return $this === self::Probation || $this === self::Active;
    }

    /** Counts against the plan's employee limit. */
    public function isCounted(): bool
    {
        return $this !== self::Terminated;
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Probation => [self::Active, self::OnLeave, self::Suspended, self::Terminated],
            self::Active => [self::OnLeave, self::Suspended, self::Terminated],
            self::OnLeave => [self::Active, self::Suspended, self::Terminated],
            self::Suspended => [self::Active, self::OnLeave, self::Terminated],
            self::Terminated => [self::Active],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum TicketStatus: string
{
    case New = 'new';
    case Open = 'open';
    case WaitingCustomer = 'waiting_customer';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Merged = 'merged';

    /** @return list<self> Statuses in which the ticket is being worked (SLA clocks may run). */
    public static function active(): array
    {
        return [self::New, self::Open, self::WaitingCustomer, self::OnHold];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::active());
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** Resolved and closed tickets may be reopened; a merged ticket is read-only for good. */
    public function isFinished(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }
}

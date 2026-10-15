<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Enums;

enum CallStatus: string
{
    case Dialing = 'dialing';
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Completed = 'completed';
    case Missed = 'missed';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';

    /** The status in the workspace language, for server-rendered places (Customer 360). */
    public function label(): string
    {
        return match ($this) {
            self::Dialing => __('Dialing'),
            self::Ringing => __('Ringing'),
            self::Answered => __('In progress'),
            self::Completed => __('Completed'),
            self::Missed => __('Missed'),
            self::Busy => __('Busy'),
            self::NoAnswer => __('No answer'),
            self::Failed => __('Failed'),
        };
    }

    /** A call in this state will change no further. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Missed, self::Busy, self::NoAnswer, self::Failed], true);
    }
}

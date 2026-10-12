<?php

declare(strict_types=1);

namespace App\Modules\Automation\Enums;

enum NodeType: string
{
    case Trigger = 'trigger';
    case Condition = 'condition';
    case Branch = 'branch';
    case Action = 'action';
    case Delay = 'delay';
    case Schedule = 'schedule';
    case Parallel = 'parallel';
    case Approval = 'approval';
    case Webhook = 'webhook';
    case Transform = 'transform';
    case Loop = 'loop';
    case ErrorHandler = 'error_handler';
    case End = 'end';

    /**
     * Named outgoing connection points a node of this type always has. A branch node adds one per case
     * and `default`; every other type routes through the unnamed (null) handle.
     *
     * @return list<string>
     */
    public function fixedHandles(): array
    {
        return match ($this) {
            self::Condition => ['true', 'false'],
            self::Approval => ['approved', 'rejected', 'error'],
            self::Loop => ['body', 'done'],
            self::Action, self::Webhook => ['error'],
            default => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

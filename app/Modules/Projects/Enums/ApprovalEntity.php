<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum ApprovalEntity: string
{
    case Task = 'task';
    case TimeEntry = 'time_entry';
    case Expense = 'expense';
    case Milestone = 'milestone';
}

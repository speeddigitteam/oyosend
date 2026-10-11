<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

enum EntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}

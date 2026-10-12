<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    /** Content may still be changed (a change after review sends the post back to draft). */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved, self::Scheduled, self::Failed], true);
    }

    public function isDeletable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved, self::Failed], true);
    }

    /** The post can be taken back to draft by its author or a reviewer. */
    public function canReturnToDraft(): bool
    {
        return in_array($this, [self::PendingApproval, self::Approved, self::Scheduled], true);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

/** A template's approval status, normalized from the provider's. Stable strings. */
enum ProviderTemplateStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';
    case InAppeal = 'in_appeal';
    case PendingDeletion = 'pending_deletion';
    /** Any status this build does not know: shown as is, never treated as approved. */
    case Unknown = 'unknown';

    public static function fromProvider(string $value): self
    {
        return match (strtoupper($value)) {
            'APPROVED' => self::Approved,
            'PENDING', 'PENDING_REVIEW' => self::Pending,
            'REJECTED' => self::Rejected,
            'PAUSED' => self::Paused,
            'DISABLED', 'LIMIT_EXCEEDED' => self::Disabled,
            'IN_APPEAL' => self::InAppeal,
            'PENDING_DELETION', 'DELETED' => self::PendingDeletion,
            default => self::Unknown,
        };
    }
}

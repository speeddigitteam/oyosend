<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

/**
 * The visual template an invoice / credit note document is rendered with.
 *
 * `Letterhead` lays the document out on the workspace's own uploaded header and footer bands
 * (the printed "pad"): the built-in seller block is hidden because the pad already carries it.
 */
enum DocumentTemplate: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Compact = 'compact';
    case Letterhead = 'letterhead';

    /** Whether this template renders the workspace's uploaded letterhead bands. */
    public function usesLetterhead(): bool
    {
        return $this === self::Letterhead;
    }
}

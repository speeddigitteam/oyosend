<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

use Carbon\CarbonImmutable;

/** The last item a source showed: continue with items older than it (by time, then id). */
final readonly class InboxCursor
{
    public function __construct(
        public CarbonImmutable $at,
        public string $id,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

/** A group of contacts the CRM can list. `id` is opaque: pass it back to `CrmAudience::contactIds()`. */
final readonly class AudienceSegment
{
    public function __construct(
        public string $id,
        public string $label,
        public AudienceSegmentKind $kind,
        public int $approximateSize,
    ) {}
}

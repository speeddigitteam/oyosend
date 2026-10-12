<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * One thing that happened with a customer, to be appended to the shared interaction timeline.
 *
 * The subject is the shared contact and/or company; a module never needs the communication module's models.
 */
final readonly class InteractionEntry
{
    public const array TYPES = ['note', 'call', 'meeting', 'email', 'sms', 'whatsapp', 'system'];

    /**
     * @param  string  $type  One of {@see TYPES}.
     * @param  string  $summary  One line, up to 200 characters (a translated sentence, written in the workspace language).
     * @param  string|null  $direction  `inbound`, `outbound` or null.
     * @param  string|null  $sourceModule  The module that recorded it: `invoicing`.
     * @param  string|null  $dedupeKey  A stable key; recording the same key again changes nothing.
     * @param  array<string, scalar|null>  $meta  Small facts (ids, numbers), never secrets or large content.
     */
    public function __construct(
        public string $type,
        public string $summary,
        public ?string $contactId = null,
        public ?string $companyId = null,
        public ?string $body = null,
        public ?string $direction = null,
        public ?CarbonInterface $occurredAt = null,
        public ?string $sourceModule = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $dedupeKey = null,
        public array $meta = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown interaction type.');
        }

        if ($contactId === null && $companyId === null) {
            throw new InvalidArgumentException('An interaction belongs to a contact or a company.');
        }

        if (trim($summary) === '' || mb_strlen($summary) > 200) {
            throw new InvalidArgumentException('An interaction summary has 1 to 200 characters.');
        }

        if ($direction !== null && ! in_array($direction, ['inbound', 'outbound'], true)) {
            throw new InvalidArgumentException('An interaction direction is inbound or outbound.');
        }

        if ($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 160)) {
            throw new InvalidArgumentException('A dedupe key has 1 to 160 characters.');
        }
    }
}

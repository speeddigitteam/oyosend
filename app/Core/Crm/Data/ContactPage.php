<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

/** One cursor page of shared contact ids, ascending by id. Pass `nextCursor` as `$afterId` for the next page. */
final readonly class ContactPage
{
    /** @param list<string> $ids */
    public function __construct(
        public array $ids,
        public ?string $nextCursor = null,
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}

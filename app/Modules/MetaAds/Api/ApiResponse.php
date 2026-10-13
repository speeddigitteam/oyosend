<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

/** A successful Marketing API answer: the decoded JSON and the quota usage Meta reported with it. */
final readonly class ApiResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public ApiUsage $usage,
    ) {}

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = $this->data['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** The cursor of the next page, or null on the last page. */
    public function nextCursor(): ?string
    {
        $after = $this->data['paging']['cursors']['after'] ?? null;
        $hasNext = isset($this->data['paging']['next']);

        return $hasNext && is_string($after) && $after !== '' ? $after : null;
    }
}

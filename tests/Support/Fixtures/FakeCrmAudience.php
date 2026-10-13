<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Data\AudienceSegment;
use App\Core\Crm\Data\ContactPage;
use App\Core\Identity\Models\TenantMembership;
use Tests\Support\InteractsWithCrmContracts;

/**
 * Stand-in for the CRM's CrmAudience in other apps' tests: fixed segments with fixed contact ids, paged by id
 * with the real cursor rules. Bound by {@see InteractsWithCrmContracts::fakeCrmAudience()}.
 */
final class FakeCrmAudience implements CrmAudience
{
    /** @var list<array{string, ?string}> segment id and viewer membership id of every contactIds() call */
    public array $calls = [];

    /**
     * @param  list<AudienceSegment>  $segments
     * @param  array<string, list<string>>  $contacts  segment id => contact ids
     */
    public function __construct(private readonly array $segments = [], private readonly array $contacts = []) {}

    public function segments(?TenantMembership $viewer = null): array
    {
        return $this->segments;
    }

    public function contactIds(string $segmentId, int $limit = 500, ?string $afterId = null, ?TenantMembership $viewer = null): ContactPage
    {
        $this->calls[] = [$segmentId, $viewer?->id];

        $ids = $this->contacts[$segmentId] ?? [];
        sort($ids);
        $limit = max(1, min($limit, self::MAX_PAGE));

        if ($afterId !== null) {
            $ids = array_values(array_filter($ids, static fn (string $id): bool => $id > $afterId));
        }

        $page = array_slice($ids, 0, $limit);

        return new ContactPage($page, count($ids) > $limit && $page !== [] ? $page[count($page) - 1] : null);
    }
}

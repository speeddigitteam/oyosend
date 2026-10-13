<?php

declare(strict_types=1);

namespace App\Core\Crm\Contracts;

use App\Core\Crm\Data\AudienceSegment;
use App\Core\Crm\Data\ContactPage;
use App\Core\Identity\Models\TenantMembership;

/**
 * The CRM's customer groups as a campaign audience source (ADR 0023). Read-only, tenant-scoped, ids only.
 *
 * It lists who the CRM knows; it decides nothing about consent or suppression. A campaign still sends through
 * `MarketingAudience` / `ChannelGateway`, which re-check both at queue time and at send time.
 *
 * `$viewer` is the member a campaign is built for (its owner or the person who launched it). With one, only
 * the contacts behind records inside that member's CRM record scope (own / department / branch / all of the
 * permission that governs the record) are listed or counted. Null means the whole workspace and is for trusted
 * system callers only. With no CRM in the workspace the answer is empty.
 */
interface CrmAudience
{
    public const int MAX_PAGE = 1000;

    /** @return list<AudienceSegment> */
    public function segments(?TenantMembership $viewer = null): array;

    /**
     * @param  int  $limit  1 to {@see MAX_PAGE}
     * @param  string|null  $afterId  `ContactPage::$nextCursor` of the previous page
     */
    public function contactIds(string $segmentId, int $limit = 500, ?string $afterId = null, ?TenantMembership $viewer = null): ContactPage;
}

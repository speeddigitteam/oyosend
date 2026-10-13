<?php

declare(strict_types=1);

namespace App\Core\Crm;

use App\Core\Crm\Contracts\CrmAudience;
use App\Core\Crm\Data\ContactPage;
use App\Core\Identity\Models\TenantMembership;

/** Bound when no CRM is installed: there are no segments and no contacts. */
final class NullCrmAudience implements CrmAudience
{
    public function segments(?TenantMembership $viewer = null): array
    {
        return [];
    }

    public function contactIds(string $segmentId, int $limit = 500, ?string $afterId = null, ?TenantMembership $viewer = null): ContactPage
    {
        return ContactPage::empty();
    }
}

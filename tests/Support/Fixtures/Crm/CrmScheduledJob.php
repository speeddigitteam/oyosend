<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use Tests\Support\Fixtures\RecordsTenantJob;

/** A compiled tenant-bound schedule used to prove App Store gating. */
final class CrmScheduledJob extends RecordsTenantJob implements RequiresTenantContext {}

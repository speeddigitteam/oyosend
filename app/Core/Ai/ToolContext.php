<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;

/** Who a tool runs as: always a signed-in, active member of the active workspace. */
final readonly class ToolContext
{
    public function __construct(
        public User $user,
        public TenantMembership $membership,
    ) {}
}

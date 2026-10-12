<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Http\Request;

/** Who is acting in a workspace request: the signed-in user and their active membership. */
final class Actor
{
    public function __construct(private readonly AccessChecker $access) {}

    public function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function membership(Request $request): TenantMembership
    {
        return $this->access->membership($this->user($request)) ?? abort(403);
    }

    public function can(Request $request, string $permission): bool
    {
        return $this->access->allows($this->user($request), $permission);
    }
}

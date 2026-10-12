<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Ai\ToolContext;
use App\Core\Identity\Models\User;
use Illuminate\Http\Request;

/** Resolves who a request acts as: the signed-in, active member of the workspace. Never request input. */
final class AiControllerSupport
{
    public function __construct(private readonly AccessChecker $access) {}

    public function context(Request $request): ToolContext
    {
        /** @var User $user */
        $user = $request->user('web');
        $membership = $this->access->membership($user);

        abort_if($membership === null, 403);

        return new ToolContext($user, $membership);
    }
}

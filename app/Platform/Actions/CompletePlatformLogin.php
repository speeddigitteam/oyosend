<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Core\Audit\AuditLogger;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class CompletePlatformLogin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, PlatformUser $user, bool $remember, string $method): void
    {
        Auth::guard('platform')->login($user, $remember);

        $request->session()->regenerate();

        $user->timestamps = false;
        $user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();
        $user->timestamps = true;

        $this->audit->record('platform.auth.login', $user, ['method' => $method], $user);
    }
}

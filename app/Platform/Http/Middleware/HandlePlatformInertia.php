<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Core\Http\Middleware\HandleInertiaRequests;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\Request;

final class HandlePlatformInertia extends HandleInertiaRequests
{
    protected function area(): string
    {
        return 'platform';
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => function () use ($request): array {
                $user = $request->user('platform');

                return [
                    'user' => $user instanceof PlatformUser ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'initials' => $user->initials(),
                        'role' => $user->role->value,
                        'twoFactorEnabled' => $user->hasEnabledTwoFactor(),
                    ] : null,
                ];
            },
        ];
    }
}

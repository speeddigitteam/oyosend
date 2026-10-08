<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Tests\TestCase;

/**
 * Helpers for the Super Admin panel (`admin.localhost`, guard `platform`).
 *
 * @mixin TestCase
 */
trait InteractsWithPlatform
{
    /**
     * A platform account. By default it has finished two-factor enrolment,
     * because without it every panel route redirects to the setup screen.
     */
    protected function createPlatformUser(
        PlatformRole $role = PlatformRole::SuperAdmin,
        bool $twoFactor = true,
    ): PlatformUser {
        /** @var PlatformUser $user */
        $user = PlatformUser::factory()->create(['role' => $role]);

        if ($twoFactor) {
            $this->enableTwoFactor($user);
        }

        return $user;
    }

    protected function actingAsPlatformUser(PlatformUser $user): static
    {
        return $this->actingAs($user, 'platform');
    }
}

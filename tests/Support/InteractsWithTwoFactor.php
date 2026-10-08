<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Identity\Contracts\TwoFactorAuthenticatable;
use App\Core\Identity\TwoFactor\TwoFactorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * TOTP helpers shared by workspace users and platform users.
 *
 * @mixin TestCase
 */
trait InteractsWithTwoFactor
{
    /**
     * Give the account a confirmed second factor, as if it had finished
     * enrolment. Returns its recovery codes.
     *
     * @return list<string>
     */
    protected function enableTwoFactor(TwoFactorAuthenticatable&Model $account): array
    {
        app(TwoFactorService::class)->enable($account);

        $account->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();

        return $account->recoveryCodes();
    }

    /**
     * The code the account's authenticator app shows right now.
     *
     * A code can only be used once, so a test that needs a second valid code
     * asks for the next time step with `$stepsAhead: 1` (still inside the
     * verification window) instead of waiting 30 seconds.
     */
    protected function twoFactorCode(TwoFactorAuthenticatable $account, int $stepsAhead = 0): string
    {
        $engine = app(Google2FA::class);

        return $engine->oathTotp((string) $account->twoFactorSecret(), $engine->getTimestamp() + $stepsAhead);
    }

    /**
     * A well-formed code that the account's authenticator would not produce
     * now, in the step before or in the step after.
     */
    protected function wrongTwoFactorCode(TwoFactorAuthenticatable $account): string
    {
        $valid = [
            $this->twoFactorCode($account, -1),
            $this->twoFactorCode($account),
            $this->twoFactorCode($account, 1),
            $this->twoFactorCode($account, 2),
        ];

        for ($candidate = 0; $candidate < 10; $candidate++) {
            $code = str_repeat((string) $candidate, 6);

            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }

        return '000001';
    }
}

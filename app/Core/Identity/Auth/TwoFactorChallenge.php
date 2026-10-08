<?php

declare(strict_types=1);

namespace App\Core\Identity\Auth;

use App\Core\Identity\Contracts\TwoFactorAuthenticatable;
use App\Core\Identity\TwoFactor\TwoFactorService;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a second-factor response is good: either a current
 * authenticator code, or one unused recovery code (which is then burned).
 */
final class TwoFactorChallenge
{
    public const string METHOD_CODE = 'two_factor';

    public const string METHOD_RECOVERY = 'recovery_code';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * @return self::METHOD_*|null The method that succeeded, or null on failure.
     */
    public function attempt(TwoFactorAuthenticatable&Model $account, ?string $code, ?string $recoveryCode): ?string
    {
        if (is_string($code) && $code !== '' && $this->twoFactor->verify($account, $code)) {
            return self::METHOD_CODE;
        }

        if (
            is_string($recoveryCode)
            && $recoveryCode !== ''
            && method_exists($account, 'consumeRecoveryCode')
            && $account->consumeRecoveryCode($recoveryCode)
        ) {
            return self::METHOD_RECOVERY;
        }

        return null;
    }
}

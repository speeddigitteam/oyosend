<?php

declare(strict_types=1);

namespace App\Core\Identity\Contracts;

/**
 * An account that can be protected with TOTP two-factor authentication.
 * Implemented by workspace users and platform users alike.
 */
interface TwoFactorAuthenticatable
{
    public function hasEnabledTwoFactor(): bool;

    public function twoFactorSecret(): ?string;

    /**
     * @return list<string>
     */
    public function recoveryCodes(): array;

    /**
     * Stable identifier used to bind replay protection and throttling to this account.
     */
    public function twoFactorKey(): string;

    /**
     * Label shown in the authenticator app.
     */
    public function twoFactorLabel(): string;
}

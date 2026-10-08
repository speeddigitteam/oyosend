<?php

declare(strict_types=1);

namespace App\Core\Identity\Concerns;

/**
 * Storage side of two-factor authentication. The secret and recovery codes
 * are encrypted at rest (see the model's casts) and hidden from serialization.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasTwoFactorAuthentication
{
    public function hasEnabledTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function twoFactorSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    /**
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return array_values($this->two_factor_recovery_codes ?? []);
    }

    public function twoFactorKey(): string
    {
        return $this->getMorphClass().':'.$this->getKey();
    }

    public function twoFactorLabel(): string
    {
        return (string) $this->email;
    }

    /**
     * Burn a recovery code. Returns false when the code is not one of ours.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $code = trim($code);
        $remaining = [];
        $found = false;

        foreach ($this->recoveryCodes() as $candidate) {
            if (! $found && hash_equals($candidate, $code)) {
                $found = true;

                continue;
            }

            $remaining[] = $candidate;
        }

        if ($found) {
            $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $found;
    }
}

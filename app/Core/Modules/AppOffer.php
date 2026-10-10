<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\AppStore\Enums\AppBillingType;
use InvalidArgumentException;

/**
 * One way an optional module can be licensed.
 *
 * Money is always an integer number of minor units. Usage-based and
 * enterprise offers are visible in the catalogue but are not self-serve.
 */
final readonly class AppOffer
{
    public function __construct(
        public string $key,
        public string $name,
        public AppBillingType $type,
        public ?string $currency = null,
        public ?int $amount = null,
        public int $trialDays = 0,
        public bool $public = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid app offer key [{$key}].");
        }

        if ($trialDays < 0 || $trialDays > 365) {
            throw new InvalidArgumentException("Invalid trial length for app offer [{$key}].");
        }

        if ($type === AppBillingType::Free) {
            if (($amount ?? 0) !== 0) {
                throw new InvalidArgumentException("Free app offer [{$key}] cannot have a price.");
            }

            return;
        }

        if ($type->isSelfServe() && ($currency === null || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || $amount === null || $amount <= 0)) {
            throw new InvalidArgumentException("Paid app offer [{$key}] needs a positive minor-unit amount and currency.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'trial_days' => $this->trialDays,
            'public' => $this->public,
        ];
    }
}

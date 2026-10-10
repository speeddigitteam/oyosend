<?php

declare(strict_types=1);

namespace App\Core\Billing\Exceptions;

use RuntimeException;

/**
 * A payment gateway could not do what was asked: it is unreachable, refused
 * the request, or lacks something it needs.
 */
final class GatewayException extends RuntimeException
{
    private bool $missingBillingDetails = false;

    /**
     * The provider requires customer details the workspace has not filled in.
     */
    public static function missingBillingDetails(string $gateway): self
    {
        $exception = new self("[{$gateway}] needs the phone number, address, city, postal code and country of the company.");
        $exception->missingBillingDetails = true;

        return $exception;
    }

    public function isMissingBillingDetails(): bool
    {
        return $this->missingBillingDetails;
    }
}

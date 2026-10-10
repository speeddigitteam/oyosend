<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Billing\Models\UsageRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The one place usage of the active workspace is counted: messages sent,
 * credits spent, workflow runs.
 *
 * Every event carries an idempotency key that is unique within the workspace
 * (`sms:{message id}`, `automation:{execution id}`). Reporting the same event
 * again - a retried job, a duplicate webhook - counts nothing.
 */
final class UsageMeter
{
    /**
     * @param  string  $meter  A registered limit key: `sms.messages`.
     * @param  array<string, scalar|null>  $meta  Small facts for later reporting. Never content or secrets.
     * @return bool Whether the event was counted (false: it had been counted before).
     */
    public function record(string $meter, int $quantity, string $idempotencyKey, array $meta = [], ?CarbonInterface $occurredAt = null): bool
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Usage is recorded in whole units of at least one.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new InvalidArgumentException('An idempotency key of 1 to 120 characters is required.');
        }

        try {
            UsageRecord::query()->create([
                'meter' => $meter,
                'quantity' => $quantity,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta === [] ? null : $meta,
                'occurred_at' => $occurredAt ?? Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Units consumed from `$from` (inclusive) to `$to` (exclusive); open-ended when null.
     */
    public function used(string $meter, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        return (int) UsageRecord::query()
            ->where('meter', $meter)
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<', $to))
            ->sum('quantity');
    }
}

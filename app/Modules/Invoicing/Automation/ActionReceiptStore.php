<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Automation;

use App\Modules\Invoicing\Models\AutomationActionReceipt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Executes a tenant automation side effect once and durably remembers its output.
 */
final class ActionReceiptStore
{
    /**
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function run(string $action, string $idempotencyKey, Closure $operation): array
    {
        if ($action === '' || strlen($action) > 100) {
            throw new InvalidArgumentException('An action key of 1 to 100 characters is required.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('An idempotency key of 1 to 160 characters is required.');
        }

        $receipt = $this->find($action, $idempotencyKey);

        if ($receipt !== null) {
            return $receipt->output;
        }

        try {
            return DB::transaction(function () use ($action, $idempotencyKey, $operation): array {
                $receipt = $this->find($action, $idempotencyKey);

                if ($receipt !== null) {
                    return $receipt->output;
                }

                $receipt = AutomationActionReceipt::query()->create([
                    'action' => $action,
                    'idempotency_key' => $idempotencyKey,
                    'output' => [],
                ]);
                $output = $operation();
                $receipt->update(['output' => $output]);

                // Return the database round-trip representation so the first
                // execution and every replay expose an identical JSON shape.
                return $receipt->refresh()->output;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent worker may have committed the same receipt while this
            // transaction was waiting on the unique index. Its stored result is
            // the authoritative output for every retry.
            $receipt = $this->find($action, $idempotencyKey);

            if ($receipt === null) {
                throw $exception;
            }

            return $receipt->output;
        }
    }

    private function find(string $action, string $idempotencyKey): ?AutomationActionReceipt
    {
        return AutomationActionReceipt::query()
            ->where('action', $action)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}

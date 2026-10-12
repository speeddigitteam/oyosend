<?php

declare(strict_types=1);

namespace App\Modules\Communication\Automation;

use App\Modules\Communication\Models\AutomationActionReceipt;
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

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 140) {
            throw new InvalidArgumentException('An idempotency key of 1 to 140 characters is required.');
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

                return $receipt->refresh()->output;
            });
        } catch (UniqueConstraintViolationException $exception) {
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

<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation;

use App\Modules\WhatsappMarketing\Models\AutomationActionReceipt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Executes a tenant automation side effect once and durably remembers its output: a retried step with the same
 * action and key returns the stored output instead of sending or assigning again.
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
                $receipt->update(['output' => $operation()]);

                // The round-trip representation, so the first run and every replay have one JSON shape.
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

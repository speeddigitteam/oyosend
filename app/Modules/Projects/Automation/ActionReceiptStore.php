<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation;

use App\Modules\Projects\Models\AutomationActionReceipt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ActionReceiptStore
{
    /**
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function run(string $action, string $idempotencyKey, Closure $operation): array
    {
        if ($action === '' || strlen($action) > 100 || $idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('Valid action and idempotency keys are required.');
        }

        $existing = $this->find($action, $idempotencyKey);

        if ($existing !== null) {
            return $existing->output;
        }

        try {
            return DB::transaction(function () use ($action, $idempotencyKey, $operation): array {
                $receipt = $this->find($action, $idempotencyKey);

                if ($receipt !== null) {
                    return $receipt->output;
                }

                /** @var AutomationActionReceipt $receipt */
                $receipt = AutomationActionReceipt::query()->create(['action' => $action, 'idempotency_key' => $idempotencyKey, 'output' => []]);
                $receipt->update(['output' => $operation()]);

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

    private function find(string $action, string $key): ?AutomationActionReceipt
    {
        return AutomationActionReceipt::query()->where('action', $action)->where('idempotency_key', $key)->first();
    }
}

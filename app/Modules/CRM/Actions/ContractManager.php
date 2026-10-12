<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContractManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, TenantMembership $owner): Contract
    {
        $attributes = $this->normalize($attributes);

        return DB::transaction(function () use ($attributes, $owner): Contract {
            $contract = new Contract([
                ...$attributes,
                'number' => 'CT-'.strtoupper(substr((string) Str::ulid(), -12)),
                'status' => ContractStatus::Draft,
            ]);
            $contract->assignOwner($owner);
            $contract->save();

            return $contract;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Contract $contract, array $attributes): Contract
    {
        $attributes = $this->normalize($attributes);

        return DB::transaction(function () use ($contract, $attributes): Contract {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ContractStatus::Draft) {
                throw ValidationException::withMessages(['contract' => __('Only a draft contract can be edited.')]);
            }

            if ($locked->sales_order_id !== null) {
                foreach (['contact_id', 'company_id', 'currency', 'value_amount'] as $field) {
                    if (array_key_exists($field, $attributes) && $attributes[$field] !== $locked->getAttribute($field)) {
                        throw ValidationException::withMessages([$field => __('The value, currency and customer of a contract linked to an order cannot be changed.')]);
                    }
                }
            }

            $locked->fill($attributes);
            $locked->save();

            return $locked;
        });
    }

    public function transition(Contract $contract, ContractStatus $status): Contract
    {
        return DB::transaction(function () use ($contract, $status): Contract {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            $allowed = match ($locked->status) {
                ContractStatus::Draft => [ContractStatus::Active, ContractStatus::Terminated],
                ContractStatus::Active => [ContractStatus::Expired, ContractStatus::Terminated],
                ContractStatus::Expired, ContractStatus::Terminated => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => __('That contract status change is not allowed.')]);
            }

            $locked->status = $status;
            $locked->signed_at = $status === ContractStatus::Active ? ($locked->signed_at ?? now()) : $locked->signed_at;
            $locked->terminated_at = $status === ContractStatus::Terminated ? now() : null;
            $locked->save();
            $this->audit->record('crm.contract.status_changed', $locked, ['status' => $status->value]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $order = isset($attributes['sales_order_id'])
            ? SalesOrder::query()->findOrFail($attributes['sales_order_id'])
            : null;

        if ($order !== null) {
            $attributes['contact_id'] = $order->contact_id;
            $attributes['company_id'] = $order->company_id;
            $attributes['currency'] = $order->currency;
            $attributes['value_amount'] = $order->total_amount;
        } else {
            $attributes['currency'] = strtoupper((string) ($attributes['currency'] ?? ''));
        }

        if (($attributes['contact_id'] ?? null) === null && ($attributes['company_id'] ?? null) === null) {
            throw ValidationException::withMessages(['customer' => __('Choose a contact or company.')]);
        }

        if (($attributes['contact_id'] ?? null) !== null) {
            Contact::query()->findOrFail($attributes['contact_id']);
        }

        if (($attributes['company_id'] ?? null) !== null) {
            Company::query()->findOrFail($attributes['company_id']);
        }

        if (($attributes['ends_on'] ?? null) !== null && (string) $attributes['ends_on'] < (string) $attributes['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('The end date must be on or after the start date.')]);
        }

        if (($attributes['renews_on'] ?? null) !== null && (string) $attributes['renews_on'] < (string) $attributes['starts_on']) {
            throw ValidationException::withMessages(['renews_on' => __('The renewal date must be on or after the start date.')]);
        }

        return $attributes;
    }
}

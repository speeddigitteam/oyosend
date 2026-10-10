<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Brick\Math\Exception\IntegerOverflowException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Moves every CRM-owned reference when two shared contacts are merged. */
final class CrmContactMergeReference implements ContactMergeReference
{
    private const array DIRECT_TABLES = [
        'crm_leads',
        'crm_deals',
        'crm_activities',
        'crm_notes',
        'crm_timeline_entries',
        'crm_quotes',
        'crm_sales_orders',
        'crm_contracts',
    ];

    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        $counts = [];

        foreach (self::DIRECT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $counts[$table] = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $duplicateId)
                ->update(['contact_id' => $canonicalId]);
        }

        $counts['crm_customer_profiles'] = $this->mergeCustomerProfiles($tenantId, $canonicalId, $duplicateId);
        $counts['crm_taggables'] = $this->moveUniquePolymorphic(
            'crm_taggables',
            'tag_id',
            $tenantId,
            $canonicalId,
            $duplicateId,
        );
        $counts['crm_custom_field_values'] = $this->moveUniquePolymorphic(
            'crm_custom_field_values',
            'custom_field_id',
            $tenantId,
            $canonicalId,
            $duplicateId,
        );
        $counts['crm_attachments'] = $this->movePolymorphic(
            'crm_attachments',
            $tenantId,
            $canonicalId,
            $duplicateId,
        );

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    private function mergeCustomerProfiles(string $tenantId, string $canonicalId, string $duplicateId): int
    {
        if (! Schema::hasTable('crm_customer_profiles')) {
            return 0;
        }

        $canonical = DB::table('crm_customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $canonicalId)
            ->lockForUpdate()
            ->first();
        $duplicate = DB::table('crm_customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $duplicateId)
            ->lockForUpdate()
            ->first();

        if (! $duplicate instanceof stdClass) {
            return 0;
        }

        if (! $canonical instanceof stdClass) {
            return DB::table('crm_customer_profiles')
                ->where('tenant_id', $tenantId)
                ->where('id', $duplicate->id)
                ->update(['contact_id' => $canonicalId]);
        }

        DB::table('crm_customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('id', $canonical->id)
            ->update([
                'stage' => $this->lifecycleStage($canonical->stage, $duplicate->stage),
                'lifetime_values' => json_encode(
                    $this->lifetimeValues($canonical->lifetime_values, $duplicate->lifetime_values),
                    JSON_THROW_ON_ERROR,
                ),
                'first_won_at' => $this->earliest($canonical->first_won_at, $duplicate->first_won_at),
                'last_won_at' => $this->latest($canonical->last_won_at, $duplicate->last_won_at),
                'last_activity_at' => $this->latest($canonical->last_activity_at, $duplicate->last_activity_at),
                'next_follow_up_at' => $this->earliest($canonical->next_follow_up_at, $duplicate->next_follow_up_at),
                'updated_at' => now(),
            ]);

        DB::table('crm_customer_profiles')
            ->where('tenant_id', $tenantId)
            ->where('id', $duplicate->id)
            ->delete();

        return 1;
    }

    private function moveUniquePolymorphic(
        string $table,
        string $uniqueColumn,
        string $tenantId,
        string $canonicalId,
        string $duplicateId,
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $duplicateQuery = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'contact')
            ->where('entity_id', $duplicateId);
        $count = $duplicateQuery->count();

        if ($count === 0) {
            return 0;
        }

        $canonicalKeys = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'contact')
            ->where('entity_id', $canonicalId)
            ->pluck($uniqueColumn);

        if ($canonicalKeys->isNotEmpty()) {
            DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('entity_type', 'contact')
                ->where('entity_id', $duplicateId)
                ->whereIn($uniqueColumn, $canonicalKeys)
                ->delete();
        }

        DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'contact')
            ->where('entity_id', $duplicateId)
            ->update(['entity_id' => $canonicalId]);

        return $count;
    }

    private function movePolymorphic(
        string $table,
        string $tenantId,
        string $canonicalId,
        string $duplicateId,
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'contact')
            ->where('entity_id', $duplicateId)
            ->update(['entity_id' => $canonicalId]);
    }

    /** @return array<string, int> */
    private function lifetimeValues(mixed $canonical, mixed $duplicate): array
    {
        $merged = $this->decodeValues($canonical);

        foreach ($this->decodeValues($duplicate) as $currency => $amount) {
            try {
                $merged[$currency] = SalesMath::add($merged[$currency] ?? 0, $amount);
            } catch (IntegerOverflowException) {
                throw ValidationException::withMessages([
                    'duplicate_id' => __('The contacts have lifetime values too large to merge safely.'),
                ]);
            }
        }

        ksort($merged);

        return $merged;
    }

    /** @return array<string, int> */
    private function decodeValues(mixed $values): array
    {
        if (is_string($values)) {
            $values = json_decode($values, true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $currency => $amount) {
            if (is_string($currency) && is_int($amount) && $amount >= 0) {
                $normalized[strtoupper($currency)] = $amount;
            }
        }

        return $normalized;
    }

    private function earliest(?string $left, ?string $right): ?string
    {
        return match (true) {
            $left === null => $right,
            $right === null => $left,
            default => min($left, $right),
        };
    }

    private function latest(?string $left, ?string $right): ?string
    {
        return match (true) {
            $left === null => $right,
            $right === null => $left,
            default => max($left, $right),
        };
    }

    private function lifecycleStage(mixed $canonical, mixed $duplicate): string
    {
        $ranks = [
            'lead' => 0,
            'prospect' => 1,
            'customer' => 2,
            'inactive' => 3,
            'churned' => 4,
        ];
        $canonical = is_string($canonical) && array_key_exists($canonical, $ranks) ? $canonical : 'lead';
        $duplicate = is_string($duplicate) && array_key_exists($duplicate, $ranks) ? $duplicate : 'lead';

        return $ranks[$duplicate] > $ranks[$canonical] ? $duplicate : $canonical;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Models\CustomerProfile;
use Brick\Math\Exception\IntegerOverflowException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerLifecycleManager
{
    public function touchProspect(
        ?string $contactId,
        ?string $companyId,
        ?TenantMembership $owner,
    ): void {
        foreach ([['contact_id', $contactId], ['company_id', $companyId]] as [$column, $id]) {
            if ($id === null) {
                continue;
            }

            /** @var CustomerProfile|null $profile */
            $profile = CustomerProfile::query()->where($column, $id)->lockForUpdate()->first();

            if ($profile === null) {
                $profile = new CustomerProfile([
                    $column => $id,
                    'stage' => CustomerLifecycleStage::Prospect,
                    'lifetime_values' => [],
                ]);
                $profile->assignOwner($owner);
            } elseif ($profile->stage === CustomerLifecycleStage::Lead) {
                $profile->stage = CustomerLifecycleStage::Prospect;
            }

            $profile->last_activity_at = now();
            $profile->save();
        }
    }

    public function recordWon(
        ?string $contactId,
        ?string $companyId,
        int $amount,
        string $currency,
        CarbonInterface $wonAt,
        ?TenantMembership $owner,
    ): void {
        foreach ([['contact_id', $contactId], ['company_id', $companyId]] as [$column, $id]) {
            if ($id === null) {
                continue;
            }

            /** @var CustomerProfile|null $profile */
            $profile = CustomerProfile::query()->where($column, $id)->lockForUpdate()->first();

            if ($profile === null) {
                $profile = new CustomerProfile([
                    $column => $id,
                    'stage' => CustomerLifecycleStage::Customer,
                    'lifetime_values' => [],
                ]);
                $profile->assignOwner($owner);
            }

            /** @var array<string, int> $values */
            $values = $profile->lifetime_values ?? [];
            $normalizedCurrency = strtoupper($currency);

            try {
                $values[$normalizedCurrency] = SalesMath::add($values[$normalizedCurrency] ?? 0, $amount);
            } catch (IntegerOverflowException) {
                throw ValidationException::withMessages([
                    'lifetime_values' => __('The customer lifetime value is outside the supported range.'),
                ]);
            }

            $profile->fill([
                'stage' => CustomerLifecycleStage::Customer,
                'lifetime_values' => $values,
                'first_won_at' => $profile->first_won_at ?? $wonAt,
                'last_won_at' => $wonAt,
                'last_activity_at' => $wonAt,
            ]);
            $profile->save();
        }
    }

    public function setStage(
        CustomerProfile $profile,
        CustomerLifecycleStage $stage,
        ?CarbonInterface $nextFollowUpAt,
    ): CustomerProfile {
        return DB::transaction(function () use ($profile, $stage, $nextFollowUpAt): CustomerProfile {
            $profile->fill([
                'stage' => $stage,
                'next_follow_up_at' => $nextFollowUpAt,
                'last_activity_at' => now(),
            ]);
            $profile->save();

            return $profile;
        });
    }
}

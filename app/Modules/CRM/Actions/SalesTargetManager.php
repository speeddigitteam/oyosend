<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Models\SalesTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesTargetManager
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, TenantMembership $owner): SalesTarget
    {
        $this->assertDates($attributes);

        return DB::transaction(function () use ($attributes, $owner): SalesTarget {
            $target = new SalesTarget($attributes);
            $target->assignOwner($owner);
            $target->save();

            return $target;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(SalesTarget $target, array $attributes): SalesTarget
    {
        $this->assertDates($attributes);

        return DB::transaction(function () use ($target, $attributes): SalesTarget {
            $target->fill($attributes);
            $target->save();

            return $target;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function assertDates(array $attributes): void
    {
        if ((string) $attributes['period_end'] < (string) $attributes['period_start']) {
            throw ValidationException::withMessages(['period_end' => __('The period end must be on or after its start.')]);
        }
    }
}

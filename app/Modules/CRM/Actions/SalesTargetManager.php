<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Modules\CRM\Models\SalesTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesTargetManager
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, TenantMembership $owner): SalesTarget
    {
        $this->assertDates($attributes);
        $scope = $this->extractScope($attributes);

        return DB::transaction(function () use ($attributes, $owner, $scope): SalesTarget {
            $target = new SalesTarget($attributes);
            $this->applyScope($target, $owner, $scope);
            $target->save();

            return $target;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(SalesTarget $target, array $attributes): SalesTarget
    {
        $this->assertDates($attributes);
        $scope = $this->extractScope($attributes);

        return DB::transaction(function () use ($target, $attributes, $scope): SalesTarget {
            $target->fill($attributes);

            if ($scope !== null) {
                $this->applyScope($target, null, $scope);
            }

            $target->save();

            return $target;
        });
    }

    /**
     * Give an unsaved or loaded target its owner / department / branch / company
     * scope. Without an explicit scope a new target belongs to its creator.
     *
     * @param  array{scope: string, owner_id: string|null, department_id: string|null, branch_id: string|null}|null  $scope
     */
    public function applyScope(SalesTarget $target, ?TenantMembership $creator, ?array $scope): SalesTarget
    {
        $kind = $scope['scope'] ?? 'owner';
        $target->setAttribute('owner_id', null);
        $target->setAttribute('department_id', null);
        $target->setAttribute('branch_id', null);

        if ($kind === 'owner') {
            $owner = ($scope['owner_id'] ?? null) === null
                ? $creator
                : TenantMembership::query()->where('status', MembershipStatus::Active)->find($scope['owner_id']);

            if ($owner === null) {
                throw ValidationException::withMessages(['owner_id' => __('Choose an active workspace member.')]);
            }

            return $target->assignOwner($owner);
        }

        $target->keepUnowned();

        if ($kind === 'department') {
            if (($scope['department_id'] ?? null) === null || Department::query()->find($scope['department_id']) === null) {
                throw ValidationException::withMessages(['department_id' => __('Choose a department.')]);
            }

            $target->setAttribute('department_id', $scope['department_id']);
        }

        if ($kind === 'branch') {
            if (($scope['branch_id'] ?? null) === null || Branch::query()->find($scope['branch_id']) === null) {
                throw ValidationException::withMessages(['branch_id' => __('Choose a branch.')]);
            }

            $target->setAttribute('branch_id', $scope['branch_id']);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{scope: string, owner_id: string|null, department_id: string|null, branch_id: string|null}|null
     */
    private function extractScope(array &$attributes): ?array
    {
        $scope = null;

        if (isset($attributes['scope']) && is_string($attributes['scope'])) {
            $scope = [
                'scope' => $attributes['scope'],
                'owner_id' => is_string($attributes['owner_id'] ?? null) ? $attributes['owner_id'] : null,
                'department_id' => is_string($attributes['department_id'] ?? null) ? $attributes['department_id'] : null,
                'branch_id' => is_string($attributes['branch_id'] ?? null) ? $attributes['branch_id'] : null,
            ];
        }

        unset($attributes['scope'], $attributes['owner_id'], $attributes['department_id'], $attributes['branch_id']);

        return $scope;
    }

    /** @param array<string, mixed> $attributes */
    private function assertDates(array $attributes): void
    {
        if ((string) $attributes['period_end'] < (string) $attributes['period_start']) {
            throw ValidationException::withMessages(['period_end' => __('The period end must be on or after its start.')]);
        }
    }
}

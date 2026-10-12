<?php

declare(strict_types=1);

namespace App\Modules\Reports\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\Models\Role;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\User;
use App\Core\Organization\Models\Department;
use App\Core\Reporting\ReportRegistry;
use App\Modules\Reports\Enums\RangeMode;
use App\Modules\Reports\Enums\Visibility;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes saved reports. Every save re-validates the setup against the
 * provider's declaration (columns, filters, dates) and refuses a provider the person cannot run.
 */
final class SavedReportManager
{
    public const string LIMIT = 'reports.saved_reports';

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly UsageLimits $limits,
        private readonly AccessChecker $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated by `SavedReportRequest`.
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $data): SavedReport
    {
        $membership = $this->access->membership($actor) ?? abort(403);
        $attributes = $this->attributes($actor, $data);
        $this->assertNameFree($membership->id, $attributes['name'], null);
        $this->limits->ensure(self::LIMIT, 1, 'name');

        return DB::transaction(fn (): SavedReport => SavedReport::query()->create([
            ...$attributes,
            'owner_membership_id' => $membership->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, SavedReport $saved, array $data): SavedReport
    {
        $attributes = $this->attributes($actor, $data);
        $this->assertNameFree($saved->owner_membership_id, $attributes['name'], $saved->id);

        return DB::transaction(function () use ($saved, $attributes): SavedReport {
            $saved->forceFill($attributes)->save();

            return $saved;
        });
    }

    public function delete(SavedReport $saved): void
    {
        DB::transaction(static fn () => $saved->delete());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(User $actor, array $data): array
    {
        $provider = $this->registry->find((string) $data['provider_key'], $actor);

        if ($provider === null) {
            throw ValidationException::withMessages(['provider_key' => __('Choose a report you may run.')]);
        }

        $mode = RangeMode::from((string) $data['range_mode']);
        $input = ['columns' => $data['columns'] ?? [], 'filters' => $data['filters'] ?? []];

        if ($mode === RangeMode::Fixed) {
            $input['from'] = $data['range_from'] ?? null;
            $input['to'] = $data['range_to'] ?? null;
        }

        $normalised = $this->runner->normalise($provider->definition(), $input);

        $visibility = Visibility::from((string) ($data['visibility'] ?? 'private'));
        $roleId = null;
        $departmentId = null;

        if ($visibility === Visibility::Role) {
            $roleId = Role::query()->forCurrentTenant()->whereKey((string) ($data['role_id'] ?? ''))->value('id');

            if ($roleId === null) {
                throw ValidationException::withMessages(['role_id' => __('Choose a role of this workspace.')]);
            }
        }

        if ($visibility === Visibility::Department) {
            $departmentId = Department::query()->whereKey((string) ($data['department_id'] ?? ''))->value('id');

            if ($departmentId === null) {
                throw ValidationException::withMessages(['department_id' => __('Choose a department of this workspace.')]);
            }
        }

        return [
            'name' => trim((string) $data['name']),
            'provider_key' => $provider->definition()->key,
            'columns' => $normalised['columns'] === [] ? null : $normalised['columns'],
            'filters' => $normalised['filters'] === [] ? null : $normalised['filters'],
            'range_mode' => $mode,
            'range_days' => $mode === RangeMode::LastDays ? (int) ($data['range_days'] ?? ReportRunner::DEFAULT_DAYS) : ReportRunner::DEFAULT_DAYS,
            'range_from' => $mode === RangeMode::Fixed ? $normalised['from'] : null,
            'range_to' => $mode === RangeMode::Fixed ? $normalised['to'] : null,
            'visibility' => $visibility,
            'role_id' => $roleId,
            'department_id' => $departmentId,
        ];
    }

    /** @throws ValidationException */
    private function assertNameFree(?string $ownerId, string $name, ?string $exceptId): void
    {
        $exists = SavedReport::query()
            ->where('owner_membership_id', $ownerId)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('You already have a saved report with this name.')]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Events\ActivityCompleted;
use App\Modules\CRM\Events\ActivityCreated;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivityManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly TimelineRecorder $timeline) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $owner = null, ?TenantMembership $actor = null): Activity
    {
        return DB::transaction(function () use ($attributes, $owner, $actor): Activity {
            $this->guardDates($attributes);
            $this->guardReferences($attributes);
            $activity = new Activity(['status' => ActivityStatus::Planned, ...$attributes]);
            $activity->assignOwner($owner)->save();
            $this->timeline->record('crm.activity.created', __('Activity created.'), $this->links($activity), $actor, ['type' => $activity->type->value]);
            $this->audit->record('crm.activity.created', $activity, ['type' => $activity->type->value, 'due_at' => $activity->due_at?->toIso8601String()]);
            ActivityCreated::dispatch($activity);

            return $activity;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Activity $activity, array $attributes, ?TenantMembership $actor = null): Activity
    {
        return DB::transaction(function () use ($activity, $attributes, $actor): Activity {
            $this->guardDates($attributes);
            $this->guardReferences($attributes);
            $activity->fill($attributes);

            if ($activity->isDirty()) {
                $changed = array_keys($activity->getDirty());
                $activity->save();
                $this->timeline->record('crm.activity.updated', __('Activity updated.'), $this->links($activity), $actor, ['changed' => implode(',', $changed)]);
                $this->audit->record('crm.activity.updated', $activity, ['changed' => $changed]);
            }

            return $activity;
        });
    }

    public function complete(Activity $activity, ?TenantMembership $actor = null): Activity
    {
        return DB::transaction(function () use ($activity, $actor): Activity {
            /** @var Activity $locked */
            $locked = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ActivityStatus::Canceled) {
                throw ValidationException::withMessages(['activity' => __('A canceled activity cannot be completed.')]);
            }

            if ($locked->status !== ActivityStatus::Completed) {
                $locked->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
                $this->timeline->record('crm.activity.completed', __('Activity completed.'), $this->links($locked), $actor);
                $this->audit->record('crm.activity.completed', $locked);
                ActivityCompleted::dispatch($locked);
            }

            return $locked;
        });
    }

    public function cancel(Activity $activity, ?TenantMembership $actor = null): Activity
    {
        return DB::transaction(function () use ($activity, $actor): Activity {
            /** @var Activity $locked */
            $locked = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ActivityStatus::Canceled) {
                return $locked;
            }

            if ($locked->status !== ActivityStatus::Planned) {
                throw ValidationException::withMessages(['activity' => __('Only a planned activity can be canceled.')]);
            }

            $locked->update(['status' => ActivityStatus::Canceled, 'completed_at' => null]);
            $this->timeline->record('crm.activity.canceled', __('Activity canceled.'), $this->links($locked), $actor);
            $this->audit->record('crm.activity.canceled', $locked);

            return $locked;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function guardDates(array $attributes): void
    {
        if (($attributes['remind_at'] ?? null) !== null && ($attributes['due_at'] ?? null) !== null
            && (string) $attributes['remind_at'] > (string) $attributes['due_at']) {
            throw ValidationException::withMessages(['remind_at' => __('The reminder must be before the due time.')]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(array $attributes): void
    {
        $models = ['lead_id' => Lead::class, 'deal_id' => Deal::class, 'contact_id' => Contact::class, 'company_id' => Company::class];

        foreach ($models as $key => $model) {
            if (($attributes[$key] ?? null) !== null) {
                $model::query()->findOrFail($attributes[$key]);
            }
        }
    }

    /** @return array{contact_id: string|null, company_id: string|null, lead_id: string|null, deal_id: string|null, activity_id: string} */
    private function links(Activity $activity): array
    {
        return ['contact_id' => $activity->contact_id, 'company_id' => $activity->company_id, 'lead_id' => $activity->lead_id, 'deal_id' => $activity->deal_id, 'activity_id' => $activity->id];
    }
}

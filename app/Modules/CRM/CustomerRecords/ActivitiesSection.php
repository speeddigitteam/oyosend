<?php

declare(strict_types=1);

namespace App\Modules\CRM\CustomerRecords;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerAction;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Models\Activity;

/** Tasks, follow-ups, calls and meetings for the contact: planned ones first, soonest due first. */
final class ActivitiesSection implements CustomerRecordProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'crm.activities', module: 'crm', label: 'Tasks and follow-ups', permission: ActivityController::VIEW, order: 30);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $activities = $this->records->constrain(Activity::query(), $query->user, ActivityController::VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $activities)->count();
        $now = now();

        return new CustomerSection(
            items: $activities
                ->orderByRaw('case when status = ? then 0 else 1 end', [ActivityStatus::Planned->value])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->latest()
                ->limit($query->limit)
                ->get()
                ->map(function (Activity $activity) use ($now): CustomerItem {
                    $overdue = $activity->status === ActivityStatus::Planned && $activity->due_at !== null && $activity->due_at->lt($now);

                    return new CustomerItem(
                        id: $activity->id,
                        title: $activity->subject,
                        status: $overdue ? __('Overdue') : match ($activity->status) {
                            ActivityStatus::Planned => __('Planned'),
                            ActivityStatus::Completed => __('Completed'),
                            ActivityStatus::Canceled => __('Canceled'),
                        },
                        tone: $overdue ? 'danger' : match ($activity->status) {
                            ActivityStatus::Planned => 'info',
                            ActivityStatus::Completed => 'ok',
                            ActivityStatus::Canceled => 'neutral',
                        },
                        detail: match ($activity->type) {
                            ActivityType::Task => __('Task'),
                            ActivityType::Meeting => __('Meeting'),
                            ActivityType::Call => __('Call'),
                            ActivityType::FollowUp => __('Follow-up'),
                        },
                        at: $activity->due_at ?? $activity->created_at,
                    );
                })->values()->all(),
            total: $total,
            actions: $query->user->can(ActivityController::EDIT)
                ? [new CustomerAction('create_activity', 'Add task or follow-up', route('crm.activities.index', ['create_for' => $query->contact->id]))]
                : [],
        );
    }
}

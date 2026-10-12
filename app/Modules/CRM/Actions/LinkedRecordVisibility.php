<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Http\Controllers\DealController;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Notes and timeline entries on a customer can be tied to a lead, deal or
 * activity (they carry the customer's ids too). The customer page may show
 * those only to someone who can view the linked record itself.
 */
final class LinkedRecordVisibility
{
    public function __construct(private readonly RecordAccess $records) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $viewer): Builder
    {
        $sources = [
            'lead_id' => $this->records->constrain(Lead::query(), $viewer, LeadController::VIEW)->select('id'),
            'deal_id' => $this->records->constrain(Deal::query(), $viewer, DealController::VIEW)->select('id'),
            'activity_id' => $this->records->constrain(Activity::query(), $viewer, ActivityController::VIEW)->select('id'),
        ];

        foreach ($sources as $column => $visible) {
            $query->where(fn (Builder $match) => $match->whereNull($column)->orWhereIn($column, $visible));
        }

        return $query;
    }
}

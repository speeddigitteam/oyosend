<?php

declare(strict_types=1);

namespace App\Modules\CRM\CustomerRecords;

use App\Core\Access\RecordAccess;
use App\Core\Billing\Support\Money;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerAction;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Http\Controllers\DealController;
use App\Modules\CRM\Models\Deal;

/** The contact's deals, open ones first, with their stage and amount. */
final class DealsSection implements CustomerRecordProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'crm.deals', module: 'crm', label: 'Deals', permission: DealController::VIEW, order: 20);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $deals = $this->records->constrain(Deal::query(), $query->user, DealController::VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $deals)->count();

        return new CustomerSection(
            items: $deals->with('stage:id,name')
                ->orderByRaw('case when status = ? then 0 else 1 end', [DealStatus::Open->value])
                ->latest()
                ->limit($query->limit)
                ->get()
                ->map(fn (Deal $deal): CustomerItem => new CustomerItem(
                    id: $deal->id,
                    title: $deal->title,
                    status: match ($deal->status) {
                        DealStatus::Open => __('Open'),
                        DealStatus::Won => __('Won'),
                        DealStatus::Lost => __('Lost'),
                    },
                    tone: match ($deal->status) {
                        DealStatus::Open => 'info',
                        DealStatus::Won => 'ok',
                        DealStatus::Lost => 'danger',
                    },
                    detail: $deal->stage->name.' · '.Money::format($deal->amount, $deal->currency),
                    at: $deal->created_at,
                    url: route('crm.deals.show', $deal),
                ))->values()->all(),
            total: $total,
            actions: $query->user->can(DealController::EDIT)
                ? [new CustomerAction('create_deal', 'Create deal', route('crm.deals.index', ['create_for' => $query->contact->id]))]
                : [],
        );
    }
}

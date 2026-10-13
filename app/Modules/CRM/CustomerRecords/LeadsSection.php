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
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Models\Lead;

final class LeadsSection implements CustomerRecordProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'crm.leads', module: 'crm', label: 'Leads', permission: LeadController::VIEW, order: 10);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $leads = $this->records->constrain(Lead::query(), $query->user, LeadController::VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $leads)->count();

        return new CustomerSection(
            items: $leads->latest()->limit($query->limit)->get()->map(fn (Lead $lead): CustomerItem => new CustomerItem(
                id: $lead->id,
                title: $lead->title,
                status: match ($lead->status) {
                    LeadStatus::New => __('New'),
                    LeadStatus::Contacted => __('Contacted'),
                    LeadStatus::Qualified => __('Qualified'),
                    LeadStatus::Disqualified => __('Disqualified'),
                    LeadStatus::Converted => __('Converted'),
                },
                tone: match ($lead->status) {
                    LeadStatus::New => 'info',
                    LeadStatus::Contacted => 'brand',
                    LeadStatus::Qualified, LeadStatus::Converted => 'ok',
                    LeadStatus::Disqualified => 'neutral',
                },
                at: $lead->created_at,
                url: route('crm.leads.show', $lead),
            ))->values()->all(),
            total: $total,
            actions: $query->user->can(LeadController::EDIT)
                ? [new CustomerAction('create_lead', 'Create lead', route('crm.leads.index', ['create_for' => $query->contact->id]))]
                : [],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Communication\CustomerRecords;

use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\Communication\Enums\InteractionType;
use App\Modules\Communication\Http\Controllers\TimelineController;
use App\Modules\Communication\Models\Interaction;

/**
 * The latest entries of the contact's interaction timeline, across channels. As on the timeline page, the
 * contact's own visibility (checked by the registry's caller) is the only narrowing.
 */
final class InteractionsSection implements CustomerRecordProvider
{
    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'communication.timeline', module: 'communication', label: 'Recent interactions', permission: TimelineController::VIEW, order: 60);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $interactions = Interaction::query()->where('contact_id', $query->contact->id);
        $total = (clone $interactions)->count();
        $timeline = route('communication.timeline.contact.show', $query->contact);

        return new CustomerSection(
            items: $interactions->orderByDesc('occurred_at')->orderByDesc('id')->limit($query->limit)->get()->map(fn (Interaction $interaction): CustomerItem => new CustomerItem(
                id: $interaction->id,
                title: $interaction->summary,
                status: match ($interaction->type) {
                    InteractionType::Note => __('Note'),
                    InteractionType::Call => __('Call'),
                    InteractionType::Meeting => __('Meeting'),
                    InteractionType::Email => __('Email'),
                    InteractionType::Sms => __('SMS'),
                    InteractionType::Whatsapp => __('WhatsApp'),
                    InteractionType::System => __('System'),
                },
                tone: 'neutral',
                detail: match ($interaction->direction) {
                    'inbound' => __('Inbound'),
                    'outbound' => __('Outbound'),
                    default => null,
                },
                at: $interaction->occurred_at,
                url: $timeline,
            ))->values()->all(),
            total: $total,
            viewAllUrl: $timeline,
        );
    }
}

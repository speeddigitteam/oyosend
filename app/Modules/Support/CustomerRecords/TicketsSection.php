<?php

declare(strict_types=1);

namespace App\Modules\Support\CustomerRecords;

use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;

/** The contact's tickets, active ones first, narrowed as in the ticket list. */
final class TicketsSection implements CustomerRecordProvider
{
    public function __construct(private readonly TicketAccess $access) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'support.tickets', module: 'support', label: 'Support tickets', permission: SupportPermissions::TICKETS_VIEW, order: 50);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $tickets = $this->access->constrain(Ticket::query(), $query->user, SupportPermissions::TICKETS_VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $tickets)->count();
        $active = array_map(static fn (TicketStatus $status): string => $status->value, [TicketStatus::New, TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold]);

        return new CustomerSection(
            items: $tickets
                ->orderByRaw('case when status in (?, ?, ?, ?) then 0 else 1 end', $active)
                ->orderByDesc('last_activity_at')
                ->latest()
                ->limit($query->limit)
                ->get()
                ->map(fn (Ticket $ticket): CustomerItem => new CustomerItem(
                    id: $ticket->id,
                    title: $ticket->number.' · '.$ticket->subject,
                    status: match ($ticket->status) {
                        TicketStatus::New => __('New'),
                        TicketStatus::Open => __('Open'),
                        TicketStatus::WaitingCustomer => __('Waiting on customer'),
                        TicketStatus::OnHold => __('On hold'),
                        TicketStatus::Resolved => __('Resolved'),
                        TicketStatus::Closed => __('Closed'),
                        TicketStatus::Merged => __('Merged'),
                    },
                    tone: match ($ticket->status) {
                        TicketStatus::New, TicketStatus::Open => 'info',
                        TicketStatus::WaitingCustomer, TicketStatus::OnHold => 'warn',
                        TicketStatus::Resolved => 'ok',
                        TicketStatus::Closed, TicketStatus::Merged => 'neutral',
                    },
                    at: $ticket->last_activity_at ?? $ticket->created_at,
                    url: route('support.tickets.show', $ticket),
                ))->values()->all(),
            total: $total,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Telephony\CustomerRecords;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Support\PhoneNumber;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Contacts\Records\CustomerAction;
use App\Core\Contacts\Records\CustomerItem;
use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallStatus;
use App\Modules\Telephony\Http\Controllers\CallController;
use App\Modules\Telephony\Http\Controllers\DialerController;
use App\Modules\Telephony\Models\Call;

/** The contact's calls on their Customer 360 timeline, and a "Call" action that opens the dialer on their number. */
final class CallsSection implements CustomerRecordProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): CustomerSectionDefinition
    {
        return new CustomerSectionDefinition(key: 'telephony.calls', module: 'telephony', label: 'Calls', permission: CallController::VIEW, order: 45);
    }

    public function forContact(CustomerQuery $query): CustomerSection
    {
        $calls = $this->records->constrain(Call::query(), $query->user, CallController::VIEW)->where('contact_id', $query->contact->id);
        $total = (clone $calls)->count();
        $callable = $query->user->can(DialerController::MAKE) && PhoneNumber::normalize($query->contact->phone) !== null;

        return new CustomerSection(
            items: $calls->latest('started_at')->limit($query->limit)->get()->map(fn (Call $call): CustomerItem => new CustomerItem(
                id: $call->id,
                title: ($call->direction === CallDirection::Inbound ? __('Incoming call') : __('Outgoing call'))
                    .($call->duration_seconds ? ' · '.gmdate($call->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $call->duration_seconds) : ''),
                status: $call->status->label(),
                tone: match ($call->status) {
                    CallStatus::Completed, CallStatus::Answered => 'ok',
                    CallStatus::Missed, CallStatus::Failed, CallStatus::Busy, CallStatus::NoAnswer => 'warn',
                    default => 'neutral',
                },
                at: $call->started_at,
                url: route('telephony.calls.index', ['search' => $call->remote_number]),
            ))->values()->all(),
            total: $total,
            actions: $callable
                ? [new CustomerAction('call', 'Call', route('telephony.dialer', ['contact' => $query->contact->id]))]
                : [],
        );
    }
}

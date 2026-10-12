<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Contacts\Models\Contact;
use App\Modules\Support\Contracts\InboundTicketMessage;
use App\Modules\Support\Contracts\InboundTicketReceiver;
use App\Modules\Support\Contracts\InboundTicketResult;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Turns a channel integration's {@see InboundTicketMessage} into a ticket or a customer reply.
 *
 * Idempotent on `channel + externalId`. A message is only threaded into an existing ticket when the sender
 * is that ticket's requester: ticket numbers are guessable, so a number alone never grants write access.
 */
final class InboundTicketHandler implements InboundTicketReceiver
{
    public function __construct(private readonly TicketManager $tickets) {}

    public function receive(InboundTicketMessage $message): InboundTicketResult
    {
        $channel = TicketChannel::tryFrom($message->channel);
        $email = mb_strtolower(trim($message->fromEmail));

        if ($channel === null || $channel === TicketChannel::Internal) {
            throw ValidationException::withMessages(['channel' => __('Unknown channel.')]);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['from_email' => __('Enter a valid email address.')]);
        }

        $externalId = trim($message->externalId);

        if ($externalId === '' || mb_strlen($externalId) > 150) {
            throw ValidationException::withMessages(['external_id' => __('A message id of up to 150 characters is required.')]);
        }

        $key = 'inbound:'.$channel->value.':'.$externalId;

        if (($duplicate = $this->existing($key)) !== null) {
            return $duplicate;
        }

        $name = $message->fromName === null ? null : mb_substr(trim($message->fromName), 0, 160);
        $thread = $this->threadTicket($message->inReplyToTicketNumber, $email);

        if ($thread !== null) {
            $stored = $this->tickets->addMessage(
                $thread,
                MessageKind::CustomerReply,
                $message->body,
                null,
                [],
                null,
                $key,
                $name,
                $email,
            );

            return new InboundTicketResult($thread->id, $thread->number, $stored->id, false, false);
        }

        $contact = Contact::query()->where('email', $email)->orderBy('created_at')->first();
        $ticket = $this->tickets->create([
            'subject' => $message->subject === '' ? __('(no subject)') : $message->subject,
            'body' => $message->body,
            'contact_id' => $contact?->id,
            'requester_name' => $name,
            'requester_email' => $email,
        ], null, $channel, $key);

        $first = Message::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->firstOrFail();

        return new InboundTicketResult($ticket->id, $ticket->number, $first->id, true, false);
    }

    private function existing(string $key): ?InboundTicketResult
    {
        $ticket = Ticket::query()->where('source_key', $key)->first();

        if ($ticket !== null) {
            $first = Message::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->first();

            return new InboundTicketResult($ticket->id, $ticket->number, (string) $first?->id, true, true);
        }

        $message = Message::query()->where('source_key', $key)->with('ticket:id,number')->first();

        return $message === null
            ? null
            : new InboundTicketResult($message->ticket_id, $message->ticket->number, $message->id, false, true);
    }

    private function threadTicket(?string $number, string $email): ?Ticket
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $ticket = Ticket::query()->with('contact:id,email')->where('number', trim($number))->first();

        // Follow a merge to its surviving ticket (a few hops at most).
        for ($hops = 0; $ticket !== null && $ticket->merged_into_id !== null && $hops < 5; $hops++) {
            $ticket = Ticket::query()->with('contact:id,email')->find($ticket->merged_into_id);
        }

        if ($ticket === null) {
            return null;
        }

        $known = array_filter([
            $ticket->requester_email === null ? null : mb_strtolower($ticket->requester_email),
            $ticket->contact?->email === null ? null : mb_strtolower($ticket->contact->email),
        ]);

        return in_array($email, $known, true) ? $ticket : null;
    }
}

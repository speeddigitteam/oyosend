<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketMerged;
use App\Modules\Support\Events\TicketSplit;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketTag;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge (many tickets of one customer into one) and split (move messages out into a new ticket).
 *
 * Both are atomic and keep every message and attachment: they only change which ticket holds them. Numbers
 * are never reused or removed, a merged ticket stays readable and points at its target, and the history of
 * both sides records what happened.
 */
final class TicketMerger
{
    public function __construct(
        private readonly TicketManager $tickets,
        private readonly TicketHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Merges `$source` into `$target`.
     *
     * @param  bool  $confirmed  Required when the two tickets do not obviously belong to the same requester.
     */
    public function merge(Ticket $source, Ticket $target, ?TenantMembership $actor, ?string $note = null, bool $confirmed = false): Ticket
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target_id' => __('Choose a different ticket to merge into.')]);
        }

        return DB::transaction(function () use ($source, $target, $actor, $note, $confirmed): Ticket {
            // Lock in a fixed order so two opposite merges cannot deadlock.
            $ids = [$source->id, $target->id];
            sort($ids);
            $locked = Ticket::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($source->id) ?? abort(404);
            $into = $locked->get($target->id) ?? abort(404);

            if ($from->status === TicketStatus::Merged || $into->status === TicketStatus::Merged) {
                throw ValidationException::withMessages(['target_id' => __('A merged ticket cannot be merged again. Choose the ticket it was merged into.')]);
            }

            if (! $into->status->isActive()) {
                throw ValidationException::withMessages(['target_id' => __('Reopen the target ticket before merging into it.')]);
            }

            $sameRequester = $this->sameRequester($from, $into);

            if (! $confirmed && ! $sameRequester) {
                throw ValidationException::withMessages(['confirm' => __('These tickets have different requesters. Confirm to merge them anyway.')]);
            }

            $now = CarbonImmutable::now();
            $messages = Message::query()->where('ticket_id', $from->id)->count();

            Message::query()->where('ticket_id', $from->id)->update(['ticket_id' => $into->id]);
            Attachment::query()->where('ticket_id', $from->id)->update(['ticket_id' => $into->id]);
            Ticket::query()->where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
            CsatSurvey::query()
                ->where('ticket_id', $from->id)
                ->where('status', SurveyStatus::Pending)
                ->update(['status' => SurveyStatus::Expired, 'token_hash' => null]);

            $existing = TicketTag::query()->where('ticket_id', $into->id)->pluck('tag_id')->all();

            foreach (TicketTag::query()->where('ticket_id', $from->id)->pluck('tag_id') as $tagId) {
                if (! in_array($tagId, $existing, true)) {
                    TicketTag::query()->create(['ticket_id' => $into->id, 'tag_id' => $tagId]);
                }
            }

            TicketTag::query()->where('ticket_id', $from->id)->delete();

            $into->contact_id ??= $from->contact_id;
            $into->company_id ??= $from->company_id;
            $into->requester_name ??= $from->requester_name;
            $into->requester_email ??= $from->requester_email;

            // A reply to somebody else's ticket is not a response to this requester: carry it over only for the
            // same requester, and only when it is the earlier one.
            if ($sameRequester
                && $from->first_responded_at !== null
                && ($into->first_responded_at === null || $from->first_responded_at->lessThan($into->first_responded_at))) {
                $into->first_responded_at = $from->first_responded_at;
                $into->first_response_remaining_minutes = null;
            }

            $into->last_customer_message_at = $this->latest($into->last_customer_message_at, $from->last_customer_message_at);
            $into->last_agent_message_at = $this->latest($into->last_agent_message_at, $from->last_agent_message_at);
            $into->last_activity_at = $now;
            $into->save();

            $from->status = TicketStatus::Merged;
            $from->merged_into_id = $into->id;
            $from->closed_at = $now;
            $from->last_activity_at = $now;
            $from->first_response_due_at = null;
            $from->resolution_due_at = null;
            $from->first_response_remaining_minutes = null;
            $from->resolution_remaining_minutes = null;
            $from->sla_paused_at = null;
            $from->save();

            $detail = ['note' => $note === null ? null : mb_substr($note, 0, 500)];
            $this->history->record($from, TicketEventKind::MergedInto, $actor, ['target_id' => $into->id, 'target_number' => $into->number] + $detail);
            $this->history->record($into, TicketEventKind::MergedFrom, $actor, ['source_id' => $from->id, 'source_number' => $from->number, 'messages' => $messages] + $detail);
            $this->audit->record('support.ticket.merged', $from, [
                'number' => $from->number,
                'into' => $into->number,
                'into_id' => $into->id,
                'messages' => $messages,
            ]);

            TicketMerged::dispatch($this->tickets->data($from) + ['target_id' => $into->id, 'target_number' => $into->number, 'messages' => $messages]);

            return $into;
        });
    }

    /**
     * Moves the chosen messages of a ticket into a new ticket of the same requester.
     *
     * @param  list<string>  $messageIds
     */
    public function split(Ticket $source, array $messageIds, string $subject, ?TenantMembership $actor): Ticket
    {
        $messageIds = array_values(array_unique($messageIds));

        if ($messageIds === []) {
            throw ValidationException::withMessages(['message_ids' => __('Choose the messages to move.')]);
        }

        return DB::transaction(function () use ($source, $messageIds, $subject, $actor): Ticket {
            $locked = $this->tickets->lock($source);
            $this->tickets->assertWritable($locked);

            $moving = Message::query()->where('ticket_id', $locked->id)->whereIn('id', $messageIds)->orderBy('created_at')->orderBy('id')->get();

            if ($moving->count() !== count($messageIds)) {
                throw ValidationException::withMessages(['message_ids' => __('Choose messages of this ticket.')]);
            }

            if (Message::query()->where('ticket_id', $locked->id)->count() - $moving->count() < 1) {
                throw ValidationException::withMessages(['message_ids' => __('Leave at least one message on the original ticket.')]);
            }

            $assignee = $locked->owner_id;

            $tags = Tag::query()
                ->whereIn('id', TicketTag::query()->where('ticket_id', $locked->id)->select('tag_id'))
                ->pluck('name')
                ->all();

            try {
                $new = $this->tickets->create([
                    'subject' => $subject,
                    'priority' => $locked->priority->value,
                    'category_id' => $locked->category_id,
                    'queue_id' => $locked->queue_id,
                    'contact_id' => $locked->contact_id,
                    'company_id' => $locked->company_id,
                    'requester_name' => $locked->requester_name,
                    'requester_email' => $locked->requester_email,
                    'assignee_id' => $assignee,
                    'tags' => $tags,
                ], $actor, $locked->channel, seedMessage: false);
            } catch (ValidationException $exception) {
                // The owner may not be an enrolled agent any more; let the routing decide instead.
                if ($assignee === null || ! array_key_exists('assignee_id', $exception->errors())) {
                    throw $exception;
                }

                $new = $this->tickets->create([
                    'subject' => $subject,
                    'priority' => $locked->priority->value,
                    'category_id' => $locked->category_id,
                    'queue_id' => $locked->queue_id,
                    'contact_id' => $locked->contact_id,
                    'company_id' => $locked->company_id,
                    'requester_name' => $locked->requester_name,
                    'requester_email' => $locked->requester_email,
                    'tags' => $tags,
                ], $actor, $locked->channel, seedMessage: false);
            }

            Message::query()->where('ticket_id', $locked->id)->whereIn('id', $messageIds)->update(['ticket_id' => $new->id]);
            Attachment::query()->where('ticket_id', $locked->id)->whereIn('message_id', $messageIds)->update(['ticket_id' => $new->id]);

            $customer = $moving->filter(fn (Message $message): bool => $message->kind === MessageKind::CustomerReply);
            $agent = $moving->filter(fn (Message $message): bool => $message->kind === MessageKind::PublicReply);
            $new->split_from_id = $locked->id;
            $new->last_customer_message_at = $customer->last()?->created_at;
            $new->last_agent_message_at = $agent->last()?->created_at;

            if ($agent->isNotEmpty()) {
                $new->first_responded_at = $agent->first()->created_at;
            }

            $new->save();

            $count = $moving->count();
            $this->history->record($locked, TicketEventKind::SplitTo, $actor, ['ticket_id' => $new->id, 'number' => $new->number, 'messages' => $count]);
            $this->history->record($new, TicketEventKind::SplitFrom, $actor, ['ticket_id' => $locked->id, 'number' => $locked->number, 'messages' => $count]);
            $this->audit->record('support.ticket.split', $locked, ['number' => $locked->number, 'new' => $new->number, 'messages' => $count]);
            TicketSplit::dispatch($this->tickets->data($locked) + ['new_ticket_id' => $new->id, 'new_number' => $new->number, 'messages' => $count]);

            return $new;
        });
    }

    private function sameRequester(Ticket $a, Ticket $b): bool
    {
        if ($a->contact_id !== null && $a->contact_id === $b->contact_id) {
            return true;
        }

        if ($a->company_id !== null && $a->company_id === $b->company_id) {
            return true;
        }

        return $a->requester_email !== null && $a->requester_email === $b->requester_email;
    }

    private function latest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $b->greaterThan($a) ? $b : $a;
    }
}

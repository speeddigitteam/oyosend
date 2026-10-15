<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Events\TicketAssigned;
use App\Modules\Support\Events\TicketClosed;
use App\Modules\Support\Events\TicketCreated;
use App\Modules\Support\Events\TicketCustomerReplied;
use App\Modules\Support\Events\TicketReopened;
use App\Modules\Support\Events\TicketReplied;
use App\Modules\Support\Events\TicketResolved;
use App\Modules\Support\Inbox\TicketInboxSource;
use App\Modules\Support\Models\Attachment;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Message;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Tag;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketTag;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every change to a ticket goes through here: one transaction, the ticket row locked, the history entry,
 * the audit entry and the domain event written together.
 */
final class TicketManager
{
    /** Metered plan limit: tickets opened per billing period (merges and replies do not count). */
    public const string LIMIT = 'support.tickets_per_month';

    public const int MAX_TAGS = 10;

    public function __construct(
        private readonly SupportSettings $settings,
        private readonly TicketNumbers $numbers,
        private readonly TicketHistory $history,
        private readonly SlaClock $clock,
        private readonly TicketRouter $router,
        private readonly AttachmentStore $attachments,
        private readonly SurveyManager $surveys,
        private readonly AuditLogger $audit,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
        private readonly InboxSignals $signals,
    ) {}

    /**
     * Opens a ticket: number, routing, SLA clocks and the requester's first message.
     *
     * @param  array<string, mixed>  $attributes  subject, body, priority?, category_id?, queue_id?, contact_id?,
     *                                            company_id?, requester_name?, requester_email?, assignee_id?, tags?
     * @param  list<UploadedFile>  $files
     */
    public function create(
        array $attributes,
        ?TenantMembership $actor,
        TicketChannel $channel = TicketChannel::Web,
        ?string $sourceKey = null,
        array $files = [],
        bool $seedMessage = true,
    ): Ticket {
        if ($sourceKey !== null && ($existing = Ticket::query()->where('source_key', $sourceKey)->first()) !== null) {
            return $existing;
        }

        $contact = $this->contact($attributes['contact_id'] ?? null);
        $company = $this->company($attributes['company_id'] ?? null);
        $categoryId = $this->categoryId($attributes['category_id'] ?? null);
        $queueId = $this->queueId($attributes['queue_id'] ?? null);
        $email = $this->email($attributes['requester_email'] ?? null) ?? $contact?->email;
        $name = $this->text($attributes['requester_name'] ?? null, 160) ?? $contact?->fullName();

        if ($contact === null && $company === null && $email === null) {
            throw ValidationException::withMessages(['contact_id' => __('Choose a customer or enter the requester\'s email address.')]);
        }

        $stored = $this->attachments->store($files);

        try {
            return DB::transaction(function () use ($attributes, $actor, $channel, $sourceKey, $contact, $company, $categoryId, $queueId, $email, $name, $stored, $seedMessage): Ticket {
                if ($sourceKey !== null && ($existing = Ticket::query()->where('source_key', $sourceKey)->first()) !== null) {
                    return $existing;
                }

                $this->limits->ensure(self::LIMIT, 1, 'subject');

                $settings = $this->settings->current();
                $priority = isset($attributes['priority'])
                    ? TicketPriority::from((string) $attributes['priority'])
                    : $settings->default_priority;
                $explicitAssignee = isset($attributes['assignee_id']) && $attributes['assignee_id'] !== ''
                    ? $this->router->resolveAssignee((string) $attributes['assignee_id'])
                    : null;
                $subject = $this->requiredText($attributes['subject'] ?? null, 200, 'subject');
                $body = $seedMessage ? $this->requiredText($attributes['body'] ?? null, 20000, 'body') : '';

                $decision = $this->router->decide(
                    $channel,
                    $priority,
                    $categoryId,
                    $company->id ?? $contact?->company_id,
                    $subject,
                    $queueId,
                    $explicitAssignee,
                    isset($attributes['priority']),
                );
                $priority = $decision['priority'] ?? $priority;
                $now = CarbonImmutable::now();
                $queue = $decision['queue_id'] === null ? null : Queue::query()->find($decision['queue_id']);

                $ticket = new Ticket([
                    'number' => $this->numbers->next($settings),
                    'subject' => $subject,
                    'status' => TicketStatus::New,
                    'priority' => $priority,
                    'channel' => $channel,
                    'category_id' => $categoryId,
                    'queue_id' => $decision['queue_id'],
                    'contact_id' => $contact?->id,
                    'company_id' => $company?->id,
                    'requester_name' => $name,
                    'requester_email' => $email,
                    'created_by' => $actor?->id,
                    'source_key' => $sourceKey,
                    'last_customer_message_at' => $now,
                    'last_activity_at' => $now,
                ]);
                $ticket->assignTo($decision['assignee'], $queue?->department_id);

                if ($decision['assignee'] !== null) {
                    $ticket->status = TicketStatus::Open;
                }

                $this->clock->start($ticket, $this->clock->policyForQueue($decision['queue_id']), $now);

                try {
                    $ticket->save();
                } catch (UniqueConstraintViolationException $exception) {
                    if ($sourceKey !== null && ($existing = Ticket::query()->where('source_key', $sourceKey)->first()) !== null) {
                        return $existing;
                    }

                    throw $exception;
                }

                $this->syncTags($ticket, $attributes['tags'] ?? []);

                if ($seedMessage) {
                    $message = Message::query()->create([
                        'ticket_id' => $ticket->id,
                        'kind' => MessageKind::CustomerReply,
                        'author_name' => $name,
                        'author_email' => $email,
                        'body' => $body,
                        'source_key' => $sourceKey === null ? null : $sourceKey.':first',
                    ]);
                    $this->persistAttachments($ticket, $message, $stored, $actor);
                }

                $this->history->record($ticket, TicketEventKind::Created, $actor, [
                    'channel' => $channel->value,
                    'priority' => $priority->value,
                    'queue_id' => $ticket->queue_id,
                    'rule_id' => $decision['rule_id'],
                ]);
                $this->audit->record('support.ticket.created', $ticket, [
                    'number' => $ticket->number,
                    'channel' => $channel->value,
                    'priority' => $priority->value,
                ]);
                $this->meter->record(self::LIMIT, 1, 'ticket:'.$ticket->id, ['number' => $ticket->number]);

                if ($decision['assignee'] !== null) {
                    $this->history->record($ticket, TicketEventKind::Assigned, $actor, ['to' => $decision['assignee']->id, 'auto' => $explicitAssignee === null]);
                    TicketAssigned::dispatch($this->data($ticket) + ['previous_owner_id' => null]);
                }

                TicketCreated::dispatch($this->data($ticket));
                $this->signals->changed(TicketInboxSource::KEY, $ticket->id);

                return $ticket;
            });
        } catch (Throwable $exception) {
            $this->attachments->discard($stored);

            throw $exception;
        }
    }

    /**
     * Edits the descriptive fields of a ticket. Priority and queue changes re-target the SLA clocks.
     *
     * @param  array<string, mixed>  $attributes  any of subject, priority, category_id, queue_id, contact_id,
     *                                            company_id, requester_name, requester_email, tags
     */
    public function update(Ticket $ticket, array $attributes, ?TenantMembership $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $attributes, $actor): Ticket {
            $locked = $this->lock($ticket);
            $this->assertWritable($locked);
            $now = CarbonImmutable::now();
            $changes = [];

            if (array_key_exists('subject', $attributes)) {
                $subject = $this->requiredText($attributes['subject'], 200, 'subject');

                if ($subject !== $locked->subject) {
                    $changes['subject'] = [$locked->subject, $subject];
                    $locked->subject = $subject;
                }
            }

            foreach (['contact_id' => 'contact', 'company_id' => 'company'] as $column => $kind) {
                if (! array_key_exists($column, $attributes)) {
                    continue;
                }

                $record = $kind === 'contact' ? $this->contact($attributes[$column]) : $this->company($attributes[$column]);

                if ($record?->id !== $locked->{$column}) {
                    $changes[$column] = [$locked->{$column}, $record?->id];
                    $locked->{$column} = $record?->id;
                }
            }

            foreach (['requester_name' => 160, 'requester_email' => 190] as $column => $max) {
                if (! array_key_exists($column, $attributes)) {
                    continue;
                }

                $value = $column === 'requester_email' ? $this->email($attributes[$column]) : $this->text($attributes[$column], $max);

                if ($value !== $locked->{$column}) {
                    $locked->{$column} = $value;
                    $changes[$column] = [null, null];
                }
            }

            if (array_key_exists('category_id', $attributes)) {
                $categoryId = $this->categoryId($attributes['category_id']);

                if ($categoryId !== $locked->category_id) {
                    $changes['category_id'] = [$locked->category_id, $categoryId];
                    $locked->category_id = $categoryId;
                }
            }

            if (isset($attributes['priority']) && $attributes['priority'] !== $locked->priority->value) {
                $old = $locked->priority;
                $new = TicketPriority::from((string) $attributes['priority']);
                $locked->priority = $new;
                $this->clock->reprioritize($locked, $old, $new, $now);
                $this->history->record($locked, TicketEventKind::PriorityChanged, $actor, ['from' => $old->value, 'to' => $new->value]);
            }

            if (array_key_exists('queue_id', $attributes)) {
                $queueId = $this->queueId($attributes['queue_id']);

                if ($queueId !== $locked->queue_id) {
                    $this->moveToQueue($locked, $queueId, $actor, $now);
                }
            }

            if (array_key_exists('tags', $attributes) && $this->syncTags($locked, $attributes['tags'] ?? [])) {
                $changes['tags'] = [null, null];
            }

            $locked->last_activity_at = $now;
            $locked->save();

            if ($changes !== []) {
                $this->history->record($locked, TicketEventKind::Updated, $actor, ['fields' => implode(',', array_keys($changes))]);
                $this->audit->record('support.ticket.updated', $locked, ['number' => $locked->number, 'fields' => array_keys($changes)]);
                $this->signals->changed(TicketInboxSource::KEY, $locked->id);
            }

            return $locked;
        });
    }

    /**
     * Writes a message on the ticket. A public reply is the first response; a customer reply re-opens a
     * waiting, resolved or closed ticket; an internal note never leaves the workspace.
     *
     * @param  list<UploadedFile>  $files
     */
    public function addMessage(
        Ticket $ticket,
        MessageKind $kind,
        string $body,
        ?TenantMembership $actor,
        array $files = [],
        ?TicketStatus $thenStatus = null,
        ?string $sourceKey = null,
        ?string $authorName = null,
        ?string $authorEmail = null,
    ): Message {
        $body = $this->requiredText($body, 20000, 'body');
        $stored = $this->attachments->store($files);

        try {
            return DB::transaction(function () use ($ticket, $kind, $body, $actor, $stored, $thenStatus, $sourceKey, $authorName, $authorEmail): Message {
                if ($sourceKey !== null && ($existing = Message::query()->where('source_key', $sourceKey)->first()) !== null) {
                    return $existing;
                }

                $locked = $this->lock($ticket);
                $this->assertWritable($locked);

                if ($locked->status === TicketStatus::Closed && $kind === MessageKind::PublicReply) {
                    throw ValidationException::withMessages(['body' => __('Reopen the ticket before replying to the customer.')]);
                }

                $now = CarbonImmutable::now();
                $customer = $kind === MessageKind::CustomerReply;
                $actor?->loadMissing('user');

                $message = Message::query()->create([
                    'ticket_id' => $locked->id,
                    'kind' => $kind,
                    'author_id' => $customer ? null : $actor?->id,
                    'author_name' => $customer ? ($authorName ?? $locked->displayRequester()) : ($actor?->user->name ?? $authorName),
                    'author_email' => $customer ? ($authorEmail ?? $locked->requester_email) : null,
                    'body' => $body,
                    'source_key' => $sourceKey,
                ]);
                $this->persistAttachments($locked, $message, $stored, $actor);

                $locked->last_activity_at = $now;

                if ($customer) {
                    $locked->last_customer_message_at = $now;
                    // A reply logged by an agent on the customer's behalf stays attributable to that agent.
                    $this->history->record($locked, TicketEventKind::CustomerReplied, $actor, ['message_id' => $message->id] + ($actor !== null ? ['logged_by' => $actor->id] : []));

                    if (in_array($locked->status, [TicketStatus::WaitingCustomer, TicketStatus::Resolved, TicketStatus::Closed], true)) {
                        $this->transition($locked, TicketStatus::Open, $actor, $now);
                    }

                    TicketCustomerReplied::dispatch($this->data($locked) + ['message_id' => $message->id]);
                } elseif ($kind === MessageKind::PublicReply) {
                    $locked->last_agent_message_at = $now;

                    if ($locked->first_responded_at === null) {
                        $locked->first_responded_at = $now;
                        $locked->first_response_remaining_minutes = null;

                        // The ticket was already waiting on the customer, but the clocks could not pause before this reply.
                        if ($locked->status === TicketStatus::WaitingCustomer && $this->clock->pause($locked, $now)) {
                            $this->history->record($locked, TicketEventKind::SlaPaused, $actor);
                        }
                    }

                    if ($locked->status === TicketStatus::New) {
                        $locked->status = TicketStatus::Open;
                    }

                    $this->history->record($locked, TicketEventKind::Replied, $actor, ['message_id' => $message->id]);
                    TicketReplied::dispatch($this->data($locked) + ['message_id' => $message->id]);
                } else {
                    $this->history->record($locked, TicketEventKind::Noted, $actor, ['message_id' => $message->id]);
                }

                $locked->save();

                if ($thenStatus !== null && ! $customer && $thenStatus !== $locked->status) {
                    $this->transition($locked, $thenStatus, $actor, $now);
                    $locked->save();
                }

                $this->audit->record('support.ticket.'.$kind->value, $locked, [
                    'number' => $locked->number,
                    'message_id' => $message->id,
                    'attachments' => count($stored),
                ]);

                // The inbox shows the conversation with the customer, never internal notes.
                if ($kind !== MessageKind::InternalNote) {
                    $this->signals->changed(TicketInboxSource::KEY, $locked->id);
                }

                return $message;
            });
        } catch (Throwable $exception) {
            $this->attachments->discard($stored);

            throw $exception;
        }
    }

    public function changeStatus(Ticket $ticket, TicketStatus $to, ?TenantMembership $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $to, $actor): Ticket {
            $locked = $this->lock($ticket);
            $this->assertWritable($locked);
            $this->transition($locked, $to, $actor, CarbonImmutable::now());
            $locked->save();
            $this->signals->changed(TicketInboxSource::KEY, $locked->id);

            return $locked;
        });
    }

    /** Assigns (or unassigns with null) a ticket. The assignee must be an active agent. */
    public function assign(Ticket $ticket, ?TenantMembership $assignee, ?TenantMembership $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $assignee, $actor): Ticket {
            $locked = $this->lock($ticket);
            $this->assertWritable($locked);

            if ($assignee !== null) {
                $assignee = $this->router->resolveAssignee($assignee->id);
            }

            $this->applyAssignment($locked, $assignee, $actor, auto: false);
            $locked->save();
            $this->signals->changed(TicketInboxSource::KEY, $locked->id);

            return $locked;
        });
    }

    /** Moves the ticket to a queue (when given) and hands it to the queue's next round-robin agent. */
    public function autoAssign(Ticket $ticket, ?string $queueId, ?TenantMembership $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $queueId, $actor): Ticket {
            $locked = $this->lock($ticket);
            $this->assertWritable($locked);
            $now = CarbonImmutable::now();

            if ($queueId !== null && $this->queueId($queueId) !== $locked->queue_id) {
                $this->moveToQueue($locked, $queueId, $actor, $now);
            }

            $queue = $locked->queue_id === null ? null : Queue::query()->find($locked->queue_id);
            $assignee = $queue === null ? null : $this->router->nextRoundRobin($queue);

            if ($assignee === null) {
                throw ValidationException::withMessages(['assignee_id' => __('No agent of this queue is available.')]);
            }

            $this->applyAssignment($locked, $assignee, $actor, auto: true);
            $locked->last_activity_at = $now;
            $locked->save();
            $this->signals->changed(TicketInboxSource::KEY, $locked->id);

            return $locked;
        });
    }

    public function lock(Ticket $ticket): Ticket
    {
        return Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, scalar|null> */
    public function data(Ticket $ticket): array
    {
        return [
            'ticket_id' => $ticket->id,
            'number' => $ticket->number,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'channel' => $ticket->channel->value,
            'queue_id' => $ticket->queue_id,
            'category_id' => $ticket->category_id,
            'contact_id' => $ticket->contact_id,
            'company_id' => $ticket->company_id,
            'owner_id' => $ticket->owner_id,
        ];
    }

    /** Refuses changes to a ticket that was merged into another: it is read-only for good. */
    public function assertWritable(Ticket $ticket): void
    {
        if ($ticket->status === TicketStatus::Merged) {
            throw ValidationException::withMessages(['ticket' => __('This ticket was merged into another ticket and can no longer be changed.')]);
        }
    }

    private function applyAssignment(Ticket $locked, ?TenantMembership $assignee, ?TenantMembership $actor, bool $auto): void
    {
        $previous = $locked->owner_id;

        if ($previous === $assignee?->id) {
            return;
        }

        $queue = $locked->queue_id === null ? null : Queue::query()->find($locked->queue_id);
        $locked->assignTo($assignee, $queue?->department_id);
        $locked->last_activity_at = CarbonImmutable::now();

        if ($assignee !== null && $locked->status === TicketStatus::New) {
            $locked->status = TicketStatus::Open;
        }

        $this->history->record(
            $locked,
            $assignee === null ? TicketEventKind::Unassigned : TicketEventKind::Assigned,
            $actor,
            ['from' => $previous, 'to' => $assignee?->id, 'auto' => $auto],
        );
        $this->audit->record($assignee === null ? 'support.ticket.unassigned' : 'support.ticket.assigned', $locked, [
            'number' => $locked->number,
            'from' => $previous,
            'to' => $assignee?->id,
        ]);

        TicketAssigned::dispatch($this->data($locked) + ['previous_owner_id' => $previous]);
    }

    private function moveToQueue(Ticket $locked, ?string $queueId, ?TenantMembership $actor, CarbonImmutable $now): void
    {
        $previous = $locked->queue_id;
        $locked->queue_id = $queueId;

        if ($locked->owner_id === null) {
            $department = $queueId === null ? null : Queue::query()->whereKey($queueId)->value('department_id');
            $locked->department_id = $department;
        }

        $policy = $this->clock->policyForQueue($queueId);

        if (($policy?->id) !== $locked->sla_policy_id) {
            $this->clock->start($locked, $policy, $locked->created_at);
        }

        $this->history->record($locked, TicketEventKind::QueueChanged, $actor, ['from' => $previous, 'to' => $queueId]);
        $this->audit->record('support.ticket.queue_changed', $locked, ['number' => $locked->number, 'from' => $previous, 'to' => $queueId]);
    }

    /** The status state machine and its SLA effects. Does not save. */
    private function transition(Ticket $locked, TicketStatus $to, ?TenantMembership $actor, CarbonImmutable $now): void
    {
        $from = $locked->status;

        if ($from === $to) {
            return;
        }

        $allowed = match ($from) {
            TicketStatus::New, TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold => [
                TicketStatus::Open, TicketStatus::WaitingCustomer, TicketStatus::OnHold, TicketStatus::Resolved, TicketStatus::Closed,
            ],
            TicketStatus::Resolved => [TicketStatus::Open, TicketStatus::Closed],
            TicketStatus::Closed => [TicketStatus::Open],
            TicketStatus::Merged => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('That status change is not allowed.')]);
        }

        $reopened = $from->isFinished() && $to === TicketStatus::Open;

        if ($to->isFinished()) {
            // Settle the clocks first: a ticket resolved while paused is judged on the time it had left.
            $this->clock->resume($locked, $now);
            $locked->resolved_at ??= $now;

            if ($to === TicketStatus::Closed) {
                $locked->closed_at = $now;
            }
        }

        if ($reopened) {
            $this->clock->keepFirstCycleBreach($locked);
            $locked->reopened_count++;
            $locked->resolved_at = null;
            $locked->closed_at = null;
            $this->clock->restartResolution($locked, $now);
        }

        if ($to === TicketStatus::WaitingCustomer) {
            if ($this->clock->pause($locked, $now)) {
                $this->history->record($locked, TicketEventKind::SlaPaused, $actor);
            }
        } elseif ($from === TicketStatus::WaitingCustomer && $this->clock->resume($locked, $now)) {
            $this->history->record($locked, TicketEventKind::SlaResumed, $actor);
        }

        $locked->status = $to;
        $locked->last_activity_at = $now;

        $this->history->record($locked, TicketEventKind::StatusChanged, $actor, ['from' => $from->value, 'to' => $to->value]);
        $this->audit->record('support.ticket.status_changed', $locked, ['number' => $locked->number, 'from' => $from->value, 'to' => $to->value]);

        if ($reopened) {
            TicketReopened::dispatch($this->data($locked));

            return;
        }

        if ($to === TicketStatus::Resolved) {
            TicketResolved::dispatch($this->data($locked));
            $this->surveys->request($locked, $actor);
        } elseif ($to === TicketStatus::Closed) {
            TicketClosed::dispatch($this->data($locked));

            if ($from !== TicketStatus::Resolved) {
                $this->surveys->request($locked, $actor);
            }
        }
    }

    /**
     * @param  list<array{path: string, name: string, mime: string, size: int}>  $stored
     */
    private function persistAttachments(Ticket $ticket, Message $message, array $stored, ?TenantMembership $actor): void
    {
        foreach ($stored as $file) {
            Attachment::query()->create([
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
                'original_name' => $file['name'],
                'path' => $file['path'],
                'mime_type' => $file['mime'],
                'size' => $file['size'],
                'uploaded_by' => $actor?->id,
            ]);
        }
    }

    /**
     * @return bool Whether the tag set changed.
     */
    private function syncTags(Ticket $ticket, mixed $tags): bool
    {
        $names = [];

        foreach (is_array($tags) ? $tags : [] as $tag) {
            $name = $this->text($tag, 60);

            if ($name !== null) {
                $names[mb_strtolower($name)] = $name;
            }
        }

        if (count($names) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('A ticket can have at most :max tags.', ['max' => self::MAX_TAGS])]);
        }

        $wanted = [];

        foreach ($names as $name) {
            $wanted[] = $this->tag($name)->id;
        }

        $current = TicketTag::query()->where('ticket_id', $ticket->id)->pluck('tag_id')->all();
        $add = array_diff($wanted, $current);
        $remove = array_diff($current, $wanted);

        foreach ($add as $tagId) {
            TicketTag::query()->create(['ticket_id' => $ticket->id, 'tag_id' => $tagId]);
        }

        if ($remove !== []) {
            TicketTag::query()->where('ticket_id', $ticket->id)->whereIn('tag_id', $remove)->delete();
        }

        return $add !== [] || $remove !== [];
    }

    private function tag(string $name): Tag
    {
        $existing = Tag::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Tag::query()->create(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            return Tag::query()->where('name', $name)->firstOrFail();
        }
    }

    private function contact(mixed $id): ?Contact
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Contact::query()->find((string) $id)
            ?? throw ValidationException::withMessages(['contact_id' => __('Choose a contact from the directory.')]);
    }

    private function company(mixed $id): ?Company
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Company::query()->find((string) $id)
            ?? throw ValidationException::withMessages(['company_id' => __('Choose a company from the directory.')]);
    }

    private function categoryId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Category::query()->where('is_active', true)->find((string) $id)->id
            ?? throw ValidationException::withMessages(['category_id' => __('Choose an active category.')]);
    }

    private function queueId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Queue::query()->where('is_active', true)->find((string) $id)->id
            ?? throw ValidationException::withMessages(['queue_id' => __('Choose an active queue.')]);
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private function email(mixed $value): ?string
    {
        $email = $this->text($value, 190);

        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['requester_email' => __('Enter a valid email address.')]);
        }

        return $email;
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        $text = $this->text($value, $max);

        if ($text === null) {
            throw ValidationException::withMessages([$field => __('This field is required.')]);
        }

        return $text;
    }
}

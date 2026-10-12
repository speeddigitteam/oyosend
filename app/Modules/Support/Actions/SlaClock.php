<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\BusinessCalendar;
use Carbon\CarbonInterface;

/**
 * The SLA arithmetic of a ticket. It only mutates the model's attributes; the caller saves inside its
 * transaction.
 *
 * - `first_response_due_at` / `resolution_due_at` are instants computed over the policy's business-hours
 *   calendar (24x7 without one) from the ticket's creation, in integer minutes.
 * - While the ticket waits on the customer (and the policy says so) the clocks are paused: the working
 *   minutes still remaining are stored, and on resume the due instants are recomputed from "now".
 * - A priority change re-targets the unmet clocks: time already used stays used.
 */
final class SlaClock
{
    public function __construct(private readonly SupportSettings $settings) {}

    /** The policy that governs a ticket in a queue: the queue's own, else the workspace default. */
    public function policyForQueue(?string $queueId): ?SlaPolicy
    {
        $policyId = $queueId === null ? null : Queue::query()->whereKey($queueId)->value('sla_policy_id');
        $policyId ??= $this->settings->current()->default_sla_policy_id;

        if ($policyId === null) {
            return null;
        }

        return SlaPolicy::query()->with('calendar')->where('is_active', true)->find($policyId);
    }

    public function policyOf(Ticket $ticket): ?SlaPolicy
    {
        if ($ticket->sla_policy_id === null) {
            return null;
        }

        return SlaPolicy::query()->with('calendar')->find($ticket->sla_policy_id);
    }

    public function calendar(?SlaPolicy $policy): BusinessCalendar
    {
        $timezone = $this->settings->timezone();
        $calendar = $policy?->calendar;

        if ($calendar === null || ! $calendar->is_active) {
            return BusinessCalendar::always($timezone);
        }

        return BusinessCalendar::make($calendar->timezone ?? $timezone, $calendar->hours, $calendar->holidays ?? []);
    }

    /** (Re)computes both targets of the unmet clocks from `$from` under `$policy` (or clears them). */
    public function start(Ticket $ticket, ?SlaPolicy $policy, CarbonInterface $from): void
    {
        $ticket->sla_policy_id = $policy?->id;
        $target = $policy?->targetFor($ticket->priority->value);
        $calendar = $this->calendar($policy);

        if ($ticket->first_responded_at === null) {
            $minutes = $target['first_response'] ?? 0;
            $ticket->first_response_due_at = $minutes > 0 ? $calendar->addMinutes($from, $minutes) : null;
            $ticket->first_response_remaining_minutes = null;
        }

        if ($ticket->resolved_at === null) {
            $minutes = $target['resolution'] ?? 0;
            $ticket->resolution_due_at = $minutes > 0 ? $calendar->addMinutes($from, $minutes) : null;
            $ticket->resolution_remaining_minutes = null;
        }

        if ($ticket->sla_paused_at !== null) {
            $this->storeRemaining($ticket, $calendar, $ticket->sla_paused_at);
        }
    }

    /** Pauses the clocks. Returns true when it changed anything. */
    public function pause(Ticket $ticket, CarbonInterface $now): bool
    {
        $policy = $this->policyOf($ticket);

        if ($ticket->sla_paused_at !== null || $policy === null || ! $policy->pause_on_waiting) {
            return false;
        }

        $this->storeRemaining($ticket, $this->calendar($policy), $now);
        $ticket->sla_paused_at = $now;

        return true;
    }

    /** Resumes paused clocks: the remaining working minutes run from `$now`. */
    public function resume(Ticket $ticket, CarbonInterface $now): bool
    {
        if ($ticket->sla_paused_at === null) {
            return false;
        }

        $calendar = $this->calendar($this->policyOf($ticket));

        if ($ticket->first_response_remaining_minutes !== null) {
            $ticket->first_response_due_at = $calendar->addMinutes($now, $ticket->first_response_remaining_minutes);
        }

        if ($ticket->resolution_remaining_minutes !== null) {
            $ticket->resolution_due_at = $calendar->addMinutes($now, $ticket->resolution_remaining_minutes);
        }

        $ticket->first_response_remaining_minutes = null;
        $ticket->resolution_remaining_minutes = null;
        $ticket->sla_paused_at = null;

        return true;
    }

    /** A reopened ticket gets a fresh resolution target counted from `$now`. */
    public function restartResolution(Ticket $ticket, CarbonInterface $now): void
    {
        $policy = $this->policyOf($ticket);
        $minutes = $policy?->targetFor($ticket->priority->value)['resolution'] ?? 0;

        $ticket->resolution_due_at = $minutes > 0 ? $this->calendar($policy)->addMinutes($now, $minutes) : null;
        $ticket->resolution_remaining_minutes = null;
        $ticket->resolution_breached_at = null;
    }

    public function reprioritize(Ticket $ticket, TicketPriority $old, TicketPriority $new, CarbonInterface $now): void
    {
        $policy = $this->policyOf($ticket);

        if ($policy === null || $old === $new) {
            return;
        }

        $calendar = $this->calendar($policy);
        $before = $policy->targetFor($old->value);
        $after = $policy->targetFor($new->value);
        $paused = $ticket->sla_paused_at !== null;

        foreach (['first_response' => $ticket->first_responded_at, 'resolution' => $ticket->resolved_at] as $clock => $done) {
            if ($done !== null) {
                continue;
            }

            $dueColumn = $clock.'_due_at';
            $remainingColumn = $clock.'_remaining_minutes';
            $newTarget = $after[$clock] ?? 0;

            if ($newTarget <= 0) {
                $ticket->{$dueColumn} = null;
                $ticket->{$remainingColumn} = null;

                continue;
            }

            $oldTarget = $before[$clock] ?? 0;
            $due = $ticket->{$dueColumn};
            $remaining = $paused
                ? $ticket->{$remainingColumn}
                : ($due === null ? null : $calendar->minutesBetween($now, $due));
            $elapsed = $oldTarget > 0 && $remaining !== null ? max(0, $oldTarget - $remaining) : 0;
            $left = max(0, $newTarget - $elapsed);

            if ($paused) {
                $ticket->{$remainingColumn} = $left;
            } else {
                $ticket->{$dueColumn} = $calendar->addMinutes($now, $left);
            }
        }
    }

    private function storeRemaining(Ticket $ticket, BusinessCalendar $calendar, CarbonInterface $at): void
    {
        $ticket->first_response_remaining_minutes = $ticket->first_responded_at === null && $ticket->first_response_due_at !== null
            ? $calendar->minutesBetween($at, $ticket->first_response_due_at)
            : null;
        $ticket->resolution_remaining_minutes = $ticket->resolved_at === null && $ticket->resolution_due_at !== null
            ? $calendar->minutesBetween($at, $ticket->resolution_due_at)
            : null;
    }
}

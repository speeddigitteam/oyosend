<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\AssignmentMode;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\AssignmentRule;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Decides queue, priority and assignee for a new ticket from the rule table, the category and the queue's
 * assignment mode. The rules are data (equality columns and one keyword); nothing is evaluated.
 */
final class TicketRouter
{
    public function __construct(private readonly SupportSettings $settings) {}

    /**
     * @param  string|null  $explicitQueueId  chosen by the person or the automation: wins over every rule
     * @param  TenantMembership|null  $explicitAssignee  chosen by the person: wins over every rule
     * @return array{queue_id: string|null, assignee: TenantMembership|null, priority: TicketPriority|null, rule_id: string|null}
     */
    public function decide(
        TicketChannel $channel,
        TicketPriority $priority,
        ?string $categoryId,
        ?string $companyId,
        string $subject,
        ?string $explicitQueueId,
        ?TenantMembership $explicitAssignee,
        bool $priorityIsExplicit,
    ): array {
        $rule = $this->matchingRule($channel, $priority, $categoryId, $companyId, $subject);

        $queueId = $explicitQueueId
            ?? $rule->queue_id
            ?? ($categoryId === null ? null : Category::query()->whereKey($categoryId)->value('queue_id'))
            ?? $this->settings->current()->default_queue_id;

        $queue = $queueId === null ? null : Queue::query()->where('is_active', true)->find($queueId);
        $assignee = $explicitAssignee;

        if ($assignee === null && $rule?->assign_agent_id !== null && ($rule->queue_id === $queue?->id)) {
            $assignee = $this->activeAgentMembership($rule->assign_agent_id);
        }

        if ($assignee === null && $queue !== null && $queue->assignment_mode === AssignmentMode::RoundRobin) {
            $assignee = $this->nextRoundRobin($queue);
        }

        return [
            'queue_id' => $queue?->id,
            'assignee' => $assignee,
            'priority' => $priorityIsExplicit ? null : $rule?->set_priority,
            'rule_id' => $rule?->id,
        ];
    }

    /** The next eligible agent of the queue after the last one chosen; null when nobody is eligible. */
    public function nextRoundRobin(Queue $queue): ?TenantMembership
    {
        $locked = Queue::query()->whereKey($queue->id)->lockForUpdate()->first();

        if ($locked === null) {
            return null;
        }

        $agents = Agent::query()
            ->whereIn('id', QueueAgent::query()->where('queue_id', $locked->id)->select('agent_id'))
            ->where('is_active', true)
            ->where('is_available', true)
            ->orderBy('id')
            ->with('membership')
            ->get()
            ->filter(fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active);

        if ($agents->isEmpty()) {
            return null;
        }

        $open = Ticket::query()
            ->whereIn('owner_id', $agents->pluck('membership_id')->all())
            ->whereIn('status', TicketStatus::activeValues())
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id');

        $eligible = $agents->filter(
            fn (Agent $agent): bool => $agent->max_open_tickets === null
                || (int) ($open[$agent->membership_id] ?? 0) < $agent->max_open_tickets,
        )->values();

        if ($eligible->isEmpty()) {
            return null;
        }

        $last = $locked->last_assigned_agent_id;
        $next = $last === null ? null : $eligible->first(fn (Agent $agent): bool => $agent->id > $last);
        $chosen = $next ?? $eligible->first();

        $locked->forceFill(['last_assigned_agent_id' => $chosen->id])->save();

        return $chosen->membership;
    }

    /** The membership of an enrolled, active agent, or a validation error naming `assignee_id`. */
    public function resolveAssignee(string $membershipId): TenantMembership
    {
        $agent = Agent::query()->where('membership_id', $membershipId)->where('is_active', true)->with('membership')->first();

        if ($agent === null || $agent->membership->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages(['assignee_id' => __('Choose an active support agent.')]);
        }

        return $agent->membership;
    }

    private function activeAgentMembership(string $agentId): ?TenantMembership
    {
        $agent = Agent::query()->where('is_active', true)->with('membership')->find($agentId);

        return $agent !== null && $agent->membership->status === MembershipStatus::Active ? $agent->membership : null;
    }

    private function matchingRule(TicketChannel $channel, TicketPriority $priority, ?string $categoryId, ?string $companyId, string $subject): ?AssignmentRule
    {
        $rules = AssignmentRule::query()->where('is_active', true)->orderBy('position')->orderBy('created_at')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if ($rule->match_channel !== null && $rule->match_channel !== $channel) {
                continue;
            }

            if ($rule->match_priority !== null && $rule->match_priority !== $priority) {
                continue;
            }

            if ($rule->match_category_id !== null && $rule->match_category_id !== $categoryId) {
                continue;
            }

            if ($rule->match_company_id !== null && $rule->match_company_id !== $companyId) {
                continue;
            }

            if ($rule->match_keyword !== null && mb_stripos($subject, $rule->match_keyword) === false) {
                continue;
            }

            return $rule;
        }

        return null;
    }
}

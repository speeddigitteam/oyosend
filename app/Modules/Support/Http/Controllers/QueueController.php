<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Company;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Department;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Enums\AssignmentMode;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Http\Requests\AgentRequest;
use App\Modules\Support\Http\Requests\CategoryRequest;
use App\Modules\Support\Http\Requests\QueueRequest;
use App\Modules\Support\Http\Requests\RuleRequest;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\AssignmentRule;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Support\SupportPermissions;
use App\Modules\Support\Support\TicketAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Agents, queues, categories and the assignment rule table (`support.queues.manage`). */
final class QueueController
{
    public function __construct(
        private readonly SupportCatalogue $catalogue,
        private readonly UsageLimits $limits,
        private readonly SupportControllerSupport $support,
        private readonly TicketAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $rules = AssignmentRule::query()->orderBy('position')->orderBy('created_at')->get();
        $companies = $this->support->companies($this->support->actor($request));
        $listed = array_column($companies, 'id');

        // A rule may match a company outside the first 500 or one the manager cannot browse: keep it nameable.
        $missing = $rules->pluck('match_company_id')->filter()->unique()->reject(fn (string $id): bool => in_array($id, $listed, true))->values()->all();

        if ($missing !== []) {
            foreach (Company::query()->whereKey($missing)->get(['id', 'name', 'email']) as $company) {
                $companies[] = ['id' => $company->id, 'name' => $company->name, 'email' => $company->email];
            }
        }

        $agents = Agent::query()->with('membership.user:id,name,email')->get()
            ->filter(fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active || $agent->is_active)
            ->sortBy(fn (Agent $agent): string => $agent->membership->user->name)->values();
        $open = Ticket::query()->whereIn('owner_id', $agents->pluck('membership_id')->all())
            ->whereIn('status', TicketStatus::activeValues())
            ->selectRaw('owner_id, count(*) as aggregate')->groupBy('owner_id')->pluck('aggregate', 'owner_id');
        $queueAgents = QueueAgent::query()->get(['queue_id', 'agent_id'])->groupBy('queue_id');
        $enrolled = $agents->pluck('membership_id')->all();

        return Inertia::render('Modules/Support/Pages/Settings/Queues', [
            'queues' => Queue::query()->orderBy('name')->get()->map(fn (Queue $queue): array => [
                'id' => $queue->id,
                'name' => $queue->name,
                'description' => $queue->description,
                'departmentId' => $queue->department_id,
                'slaPolicyId' => $queue->sla_policy_id,
                'assignmentMode' => $queue->assignment_mode->value,
                'isActive' => $queue->is_active,
                'agentIds' => ($queueAgents[$queue->id] ?? collect())->pluck('agent_id')->values()->all(),
            ])->all(),
            'agents' => $agents->map(fn (Agent $agent): array => [
                'id' => $agent->id,
                'membershipId' => $agent->membership_id,
                'name' => $agent->membership->user->name,
                'email' => $agent->membership->user->email,
                'isActive' => $agent->is_active,
                'isAvailable' => $agent->is_available,
                'maxOpenTickets' => $agent->max_open_tickets,
                'openTickets' => (int) ($open[$agent->membership_id] ?? 0),
            ])->all(),
            'candidates' => TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name,email')->get()
                ->reject(fn (TenantMembership $membership): bool => in_array($membership->id, $enrolled, true))
                ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name, 'email' => $membership->user->email])
                ->sortBy('name')->values()->all(),
            'categories' => Category::query()->orderBy('name')->get()->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'queueId' => $category->queue_id,
                'isActive' => $category->is_active,
            ])->all(),
            'companies' => $companies,
            'rules' => $rules->map(fn (AssignmentRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                'position' => $rule->position,
                'isActive' => $rule->is_active,
                'matchChannel' => $rule->match_channel?->value,
                'matchPriority' => $rule->match_priority?->value,
                'matchCategoryId' => $rule->match_category_id,
                'matchCompanyId' => $rule->match_company_id,
                'matchKeyword' => $rule->match_keyword,
                'queueId' => $rule->queue_id,
                'assignAgentId' => $rule->assign_agent_id,
                'setPriority' => $rule->set_priority?->value,
            ])->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            'policies' => SlaPolicy::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (SlaPolicy $policy): array => ['id' => $policy->id, 'name' => $policy->name, 'isActive' => $policy->is_active])->all(),
            'assignmentModes' => array_map(static fn (AssignmentMode $mode): string => $mode->value, AssignmentMode::cases()),
            'priorities' => array_map(static fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
            'channels' => array_map(static fn (TicketChannel $channel): string => $channel->value, TicketChannel::cases()),
            'agentLimit' => [
                'limit' => $this->limits->limit(SupportCatalogue::AGENT_LIMIT),
                'used' => $this->limits->used(SupportCatalogue::AGENT_LIMIT),
            ],
        ]);
    }

    public function storeQueue(QueueRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $this->assertMayChangeQueueAgents($request, null, $input);
        $this->catalogue->saveQueue(null, $input);
        Toast::success(__('Queue saved.'));

        return back();
    }

    public function updateQueue(QueueRequest $request, Queue $queue): RedirectResponse
    {
        $input = $request->validated();
        $this->assertMayChangeQueueAgents($request, $queue, $input);
        $this->catalogue->saveQueue($queue, $input);
        Toast::success(__('Queue saved.'));

        return back();
    }

    public function storeAgent(AgentRequest $request): RedirectResponse
    {
        $this->assertMayManageAgents($request);
        $input = $request->validated();
        $this->catalogue->enrolAgent((string) $input['membership_id'], $input);
        Toast::success(__('Agent enrolled.'));

        return back();
    }

    public function updateAgent(AgentRequest $request, Agent $agent): RedirectResponse
    {
        $this->assertMayManageAgents($request);
        $this->catalogue->updateAgent($agent, $request->validated());
        Toast::success(__('Agent updated.'));

        return back();
    }

    /**
     * An enrolled agent reaches the unassigned tickets of their queues with any scope they hold, so enrolling
     * people (oneself included) widens record access. Only someone who already reaches every ticket may do it.
     */
    private function assertMayManageAgents(Request $request): void
    {
        abort_unless($this->access->hasAll($this->support->actor($request), SupportPermissions::TICKETS_ASSIGN), 403);
    }

    /**
     * `agent_ids` on the queue form enrols or removes queue agents, which widens (or narrows) who reaches the
     * queue's unassigned tickets. A submitted set that differs from the stored one needs the same right as
     * managing agents; an unchanged or omitted set passes, so other queue fields stay editable.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertMayChangeQueueAgents(Request $request, ?Queue $queue, array $input): void
    {
        if (! array_key_exists('agent_ids', $input)) {
            return;
        }

        $submitted = array_values(array_unique(array_map('strval', (array) $input['agent_ids'])));
        $stored = $queue === null
            ? []
            : QueueAgent::query()->where('queue_id', $queue->id)->pluck('agent_id')->map(fn (mixed $id): string => (string) $id)->all();

        if (array_diff($submitted, $stored) === [] && array_diff($stored, $submitted) === []) {
            return;
        }

        $this->assertMayManageAgents($request);
    }

    public function storeCategory(CategoryRequest $request): RedirectResponse
    {
        $this->catalogue->saveCategory(null, $request->validated());
        Toast::success(__('Category saved.'));

        return back();
    }

    public function updateCategory(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->catalogue->saveCategory($category, $request->validated());
        Toast::success(__('Category saved.'));

        return back();
    }

    public function storeRule(RuleRequest $request): RedirectResponse
    {
        $this->catalogue->saveRule(null, $request->validated());
        Toast::success(__('Rule saved.'));

        return back();
    }

    public function updateRule(RuleRequest $request, AssignmentRule $rule): RedirectResponse
    {
        $this->catalogue->saveRule($rule, $request->validated());
        Toast::success(__('Rule saved.'));

        return back();
    }

    public function destroyRule(AssignmentRule $rule): RedirectResponse
    {
        $this->catalogue->deleteRule($rule);
        Toast::success(__('Rule deleted.'));

        return back();
    }
}

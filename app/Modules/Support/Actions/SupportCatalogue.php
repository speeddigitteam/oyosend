<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Company;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Department;
use App\Modules\Support\Enums\AssignmentMode;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\AssignmentRule;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Support\PlaceholderRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Workspace configuration of the Support app: agents, queues, categories, the assignment rule table and
 * canned responses. Every write is one transaction with its audit entry (models audit themselves).
 */
final class SupportCatalogue
{
    /** Gauge plan limit: agents enrolled and active. */
    public const string AGENT_LIMIT = 'support.agents';

    public function __construct(
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{is_available?: bool, max_open_tickets?: int|null} $attributes */
    public function enrolAgent(string $membershipId, array $attributes = []): Agent
    {
        return DB::transaction(function () use ($membershipId, $attributes): Agent {
            $membership = TenantMembership::query()->where('status', MembershipStatus::Active)->find($membershipId)
                ?? throw ValidationException::withMessages(['membership_id' => __('Choose an active member of this workspace.')]);

            $agent = Agent::query()->where('membership_id', $membership->id)->lockForUpdate()->first();

            if ($agent === null) {
                $this->limits->ensure(self::AGENT_LIMIT, 1, 'membership_id');

                return Agent::query()->create([
                    'membership_id' => $membership->id,
                    'is_active' => true,
                    'is_available' => $attributes['is_available'] ?? true,
                    'max_open_tickets' => $attributes['max_open_tickets'] ?? null,
                ]);
            }

            if (! $agent->is_active) {
                $this->limits->ensure(self::AGENT_LIMIT, 1, 'membership_id');
                $agent->is_active = true;
            }

            $agent->fill(array_intersect_key($attributes, ['is_available' => 1, 'max_open_tickets' => 1]))->save();

            return $agent;
        });
    }

    /** @param array{is_active?: bool, is_available?: bool, max_open_tickets?: int|null} $attributes */
    public function updateAgent(Agent $agent, array $attributes): Agent
    {
        return DB::transaction(function () use ($agent, $attributes): Agent {
            $locked = Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

            if (($attributes['is_active'] ?? $locked->is_active) && ! $locked->is_active) {
                $this->limits->ensure(self::AGENT_LIMIT, 1, 'is_active');
            }

            $locked->fill($attributes)->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  name, description?, department_id?, sla_policy_id?, assignment_mode, is_active, agent_ids?
     */
    public function saveQueue(?Queue $queue, array $attributes): Queue
    {
        return DB::transaction(function () use ($queue, $attributes): Queue {
            $this->assertExists(Department::class, $attributes['department_id'] ?? null, 'department_id');
            $this->assertExists(SlaPolicy::class, $attributes['sla_policy_id'] ?? null, 'sla_policy_id');
            $this->assertUnique(Queue::class, (string) $attributes['name'], $queue?->id, 'name');

            $queue ??= new Queue;
            $queue->fill([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'department_id' => $attributes['department_id'] ?? null,
                'sla_policy_id' => $attributes['sla_policy_id'] ?? null,
                'assignment_mode' => AssignmentMode::from((string) ($attributes['assignment_mode'] ?? 'manual')),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            if (array_key_exists('agent_ids', $attributes)) {
                $this->syncQueueAgents($queue, (array) $attributes['agent_ids']);
            }

            return $queue;
        });
    }

    /** @param array<string, mixed> $attributes name, queue_id?, is_active */
    public function saveCategory(?Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            $this->assertExists(Queue::class, $attributes['queue_id'] ?? null, 'queue_id');
            $this->assertUnique(Category::class, (string) $attributes['name'], $category?->id, 'name');

            $category ??= new Category;
            $category->fill([
                'name' => $attributes['name'],
                'queue_id' => $attributes['queue_id'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  name, is_active, position?, match_*, queue_id, assign_agent_id?, set_priority?
     */
    public function saveRule(?AssignmentRule $rule, array $attributes): AssignmentRule
    {
        return DB::transaction(function () use ($rule, $attributes): AssignmentRule {
            $this->assertExists(Queue::class, $attributes['queue_id'] ?? null, 'queue_id', required: true);
            $this->assertExists(Category::class, $attributes['match_category_id'] ?? null, 'match_category_id');
            $this->assertExists(Company::class, $attributes['match_company_id'] ?? null, 'match_company_id');
            $this->assertExists(Agent::class, $attributes['assign_agent_id'] ?? null, 'assign_agent_id');

            if (($attributes['assign_agent_id'] ?? null) !== null) {
                $enrolled = QueueAgent::query()
                    ->where('queue_id', $attributes['queue_id'])
                    ->where('agent_id', $attributes['assign_agent_id'])
                    ->exists();

                if (! $enrolled) {
                    throw ValidationException::withMessages(['assign_agent_id' => __('Choose an agent of the selected queue.')]);
                }
            }

            $rule ??= new AssignmentRule;
            $rule->fill([
                'name' => $attributes['name'],
                'position' => (int) ($attributes['position'] ?? 0),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'match_channel' => $attributes['match_channel'] ?? null,
                'match_priority' => $attributes['match_priority'] ?? null,
                'match_category_id' => $attributes['match_category_id'] ?? null,
                'match_company_id' => $attributes['match_company_id'] ?? null,
                'match_keyword' => $this->keyword($attributes['match_keyword'] ?? null),
                'queue_id' => $attributes['queue_id'],
                'assign_agent_id' => $attributes['assign_agent_id'] ?? null,
                'set_priority' => $attributes['set_priority'] ?? null,
            ])->save();

            return $rule;
        });
    }

    public function deleteRule(AssignmentRule $rule): void
    {
        DB::transaction(static fn () => $rule->delete());
    }

    /** @param array<string, mixed> $attributes name, body, is_active */
    public function saveCanned(?CannedResponse $response, array $attributes, ?TenantMembership $actor): CannedResponse
    {
        $body = (string) $attributes['body'];
        $unknown = PlaceholderRenderer::unknown($body);

        if ($unknown !== [] || PlaceholderRenderer::hasMalformed($body)) {
            throw ValidationException::withMessages(['body' => $unknown !== []
                ? __('Unknown placeholder: :names.', ['names' => implode(', ', $unknown)])
                : __('The text contains a malformed placeholder.')]);
        }

        return DB::transaction(function () use ($response, $attributes, $actor): CannedResponse {
            $this->assertUnique(CannedResponse::class, (string) $attributes['name'], $response?->id, 'name');

            $response ??= new CannedResponse(['created_by' => $actor?->id]);
            $response->fill([
                'name' => $attributes['name'],
                'body' => $attributes['body'],
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $response;
        });
    }

    public function deleteCanned(CannedResponse $response): void
    {
        DB::transaction(static fn () => $response->delete());
    }

    /** @param list<mixed> $agentIds */
    private function syncQueueAgents(Queue $queue, array $agentIds): void
    {
        $wanted = array_values(array_unique(array_map('strval', $agentIds)));
        $known = Agent::query()->whereIn('id', $wanted)->pluck('id')->all();

        if (count($known) !== count($wanted)) {
            throw ValidationException::withMessages(['agent_ids' => __('Choose enrolled agents.')]);
        }

        $current = QueueAgent::query()->where('queue_id', $queue->id)->pluck('agent_id')->all();

        foreach (array_diff($wanted, $current) as $agentId) {
            QueueAgent::query()->create(['queue_id' => $queue->id, 'agent_id' => $agentId]);
        }

        $remove = array_diff($current, $wanted);

        if ($remove !== []) {
            QueueAgent::query()->where('queue_id', $queue->id)->whereIn('agent_id', $remove)->delete();
        }

        if ($queue->last_assigned_agent_id !== null && in_array($queue->last_assigned_agent_id, $remove, true)) {
            $queue->forceFill(['last_assigned_agent_id' => null])->save();
        }

        if (array_diff($wanted, $current) !== [] || $remove !== []) {
            $this->audit->record('support.queue.agents_changed', $queue, ['agents' => count($wanted)]);
        }
    }

    /** @param class-string<Model> $model */
    private function assertExists(string $model, mixed $id, string $field, bool $required = false): void
    {
        if ($id === null || $id === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => __('This field is required.')]);
            }

            return;
        }

        if (! $model::query()->whereKey((string) $id)->exists()) {
            throw ValidationException::withMessages([$field => __('The selected value is not valid.')]);
        }
    }

    /** @param class-string<Model> $model */
    private function assertUnique(string $model, string $name, ?string $ignoreId, string $field): void
    {
        $exists = $model::query()
            ->where($field, $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([$field => __('That name is already in use.')]);
        }
    }

    private function keyword(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 80) : null;
    }
}

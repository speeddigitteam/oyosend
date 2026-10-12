<?php

declare(strict_types=1);

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Department;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\Category;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

beforeEach(function () {
    $this->tenant = $this->supportTenant('sup-assign');
    $this->owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $this->agents = collect(range(1, 3))->map(function () {
        [, $membership] = $this->supportMember($this->tenant, ['support.tickets.view', 'support.tickets.reply']);

        return [$membership, $this->supportAgent($this->tenant, $membership)];
    });
    $this->roundRobin = function (array $attributes = []) {
        $queue = $this->supportQueue($this->tenant, [
            'assignment_mode' => 'round_robin',
            'agent_ids' => $this->agents->map(fn ($pair) => $pair[1]->id)->all(),
            ...$attributes,
        ]);

        return $queue;
    };
    $this->assigneeOf = fn (Ticket $ticket) => $this->inTenant($this->tenant, fn () => Ticket::query()->findOrFail($ticket->id))->owner_id;
});

it('hands new tickets to the queue agents in turn', function () {
    $queue = ($this->roundRobin)();

    $owners = collect(range(1, 6))->map(fn () => $this->supportTicket($this->tenant, ['queue_id' => $queue->id])->owner_id)->all();
    $ids = $this->agents->map(fn ($pair) => $pair[0]->id)->sort()->values()->all();

    // Agents are rotated in id order, then the rotation starts over.
    expect(array_slice($owners, 0, 3))->toBe($ids)
        ->and(array_slice($owners, 3, 3))->toBe($ids);
});

it('skips agents who are unavailable, inactive or at capacity', function () {
    $queue = ($this->roundRobin)();
    $this->inTenant($this->tenant, function (): void {
        $catalogue = app(SupportCatalogue::class);
        $catalogue->updateAgent($this->agents[0][1], ['is_available' => false]);
        $catalogue->updateAgent($this->agents[1][1], ['max_open_tickets' => 1]);
    });

    $first = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);
    $second = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);
    $third = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    $eligibleCapped = $this->agents[1][0]->id;
    $free = $this->agents[2][0]->id;

    // The capped agent takes one ticket and is then skipped; the unavailable agent never receives any.
    expect(collect([$first, $second, $third])->pluck('owner_id')->filter(fn ($id) => $id === $eligibleCapped)->count())->toBe(1)
        ->and(collect([$first, $second, $third])->pluck('owner_id')->contains($this->agents[0][0]->id))->toBeFalse()
        ->and(collect([$first, $second, $third])->pluck('owner_id')->filter(fn ($id) => $id === $free)->count())->toBe(2);
});

it('leaves a ticket unassigned when nobody is eligible', function () {
    $queue = ($this->roundRobin)();
    $this->inTenant($this->tenant, function (): void {
        $this->agents->each(fn ($pair) => app(SupportCatalogue::class)->updateAgent($pair[1], ['is_available' => false]));
    });

    $ticket = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    expect($ticket->owner_id)->toBeNull()->and($ticket->status)->toBe(TicketStatus::New);
});

it('does not give a suspended member a ticket', function () {
    $queue = ($this->roundRobin)();
    $suspended = $this->agents[0][0];
    $this->inCentral(fn () => TenantMembership::withoutTenancy()->whereKey($suspended->id)->update(['status' => MembershipStatus::Suspended]));

    $owners = collect(range(1, 4))->map(fn () => $this->supportTicket($this->tenant, ['queue_id' => $queue->id])->owner_id);

    expect($owners->contains($suspended->id))->toBeFalse();
});

it('applies the first matching rule by position and respects explicit choices', function () {
    $sales = $this->supportQueue($this->tenant, ['name' => 'Sales']);
    $billing = $this->supportQueue($this->tenant, ['name' => 'Billing']);
    $fallback = $this->supportQueue($this->tenant, ['name' => 'General']);

    $this->inTenant($this->tenant, function () use ($sales, $billing, $fallback): void {
        $catalogue = app(SupportCatalogue::class);
        $catalogue->saveRule(null, ['name' => 'Urgent billing', 'position' => 1, 'match_keyword' => 'invoice', 'match_priority' => 'urgent', 'queue_id' => $billing->id, 'set_priority' => 'high']);
        $catalogue->saveRule(null, ['name' => 'Any invoice', 'position' => 2, 'match_keyword' => 'INVOICE', 'queue_id' => $billing->id]);
        $catalogue->saveRule(null, ['name' => 'Phone', 'position' => 3, 'match_channel' => 'phone', 'queue_id' => $sales->id]);
        app(SupportSettings::class)->update(['default_queue_id' => $fallback->id]);
    });

    $keyword = $this->supportTicket($this->tenant, ['subject' => 'Wrong invoice amount']);
    $urgent = $this->supportTicket($this->tenant, ['subject' => 'Invoice missing', 'priority' => 'urgent']);
    $phone = $this->supportTicket($this->tenant, ['subject' => 'Call me'], channel: TicketChannel::Phone);
    $other = $this->supportTicket($this->tenant, ['subject' => 'Something else']);
    $explicit = $this->supportTicket($this->tenant, ['subject' => 'Invoice again', 'queue_id' => $sales->id]);

    expect($keyword->queue_id)->toBe($billing->id)
        // The urgent-billing rule comes first, but an explicit priority is never overridden.
        ->and($urgent->queue_id)->toBe($billing->id)
        ->and($urgent->priority->value)->toBe('urgent')
        ->and($phone->queue_id)->toBe($sales->id)
        ->and($other->queue_id)->toBe($fallback->id)
        ->and($explicit->queue_id)->toBe($sales->id);
});

it('lets a rule set the priority when none was chosen', function () {
    $queue = $this->supportQueue($this->tenant);
    $this->inTenant($this->tenant, fn () => app(SupportCatalogue::class)->saveRule(null, [
        'name' => 'VIP', 'match_keyword' => 'outage', 'queue_id' => $queue->id, 'set_priority' => 'urgent',
    ]));

    expect($this->supportTicket($this->tenant, ['subject' => 'Full outage'])->priority->value)->toBe('urgent');
});

it('routes by category to its default queue', function () {
    $queue = $this->supportQueue($this->tenant, ['name' => 'Tech']);
    $category = $this->inTenant($this->tenant, fn () => app(SupportCatalogue::class)->saveCategory(null, ['name' => 'Technical', 'queue_id' => $queue->id]));

    expect($this->supportTicket($this->tenant, ['category_id' => $category->id])->queue_id)->toBe($queue->id);
});

it('lets a rule name a specific agent of its queue', function () {
    $queue = ($this->roundRobin)();
    [$membership, $agent] = $this->agents[2];
    $this->inTenant($this->tenant, fn () => app(SupportCatalogue::class)->saveRule(null, [
        'name' => 'To three', 'match_keyword' => 'vip', 'queue_id' => $queue->id, 'assign_agent_id' => $agent->id,
    ]));

    expect($this->supportTicket($this->tenant, ['subject' => 'VIP request'])->owner_id)->toBe($membership->id);
});

it('only accepts an enrolled, active agent as assignee', function () {
    $ticket = $this->supportTicket($this->tenant);
    [, $plain] = $this->supportMember($this->tenant, ['support.tickets.view']);   // a member, not an agent

    $this->inTenant($this->tenant, function () use ($ticket, $plain): void {
        $manager = app(TicketManager::class);

        expect(fn () => $manager->assign($ticket, $plain, $this->owner))->toThrow(ValidationException::class);

        $manager->assign($ticket, $this->agents[0][0], $this->owner);
        $assigned = Ticket::query()->findOrFail($ticket->id);

        expect($assigned->owner_id)->toBe($this->agents[0][0]->id)->and($assigned->status)->toBe(TicketStatus::Open);

        $manager->assign($ticket, null, $this->owner);
        expect(Ticket::query()->findOrFail($ticket->id)->owner_id)->toBeNull();
    });
});

it('takes the department of the assignee, or of the queue while unassigned', function () {
    $department = $this->inTenant($this->tenant, fn () => Department::query()->create(['name' => 'Support Team']));
    $queue = $this->supportQueue($this->tenant, ['department_id' => $department->id]);

    $unassigned = $this->supportTicket($this->tenant, ['queue_id' => $queue->id]);

    expect($unassigned->department_id)->toBe($department->id)->and($unassigned->owner_id)->toBeNull();
});

it('refuses deactivating past the plan limit of agents', function () {
    [, $extra] = $this->supportMember($this->tenant, ['support.tickets.view']);
    $plan = $this->createFreePlan(['key' => 'three-agents', 'modules' => ['support'], 'limits' => ['support.agents' => 3]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    // Three agents exist already (the plan allows three).
    expect(fn () => $this->supportAgent($this->tenant, $extra))->toThrow(ValidationException::class);

    // Deactivating one frees a seat.
    $this->inTenant($this->tenant, fn () => app(SupportCatalogue::class)->updateAgent($this->agents[0][1], ['is_active' => false]));
    $this->supportAgent($this->tenant, $extra);

    expect($this->inTenant($this->tenant, fn () => Agent::query()->where('is_active', true)->count()))->toBe(3);
});

it('does not use a queue from another workspace', function () {
    $other = $this->supportTenant('sup-assign-other');
    $foreign = $this->supportQueue($other);

    expect(fn () => $this->supportTicket($this->tenant, ['queue_id' => $foreign->id]))->toThrow(ValidationException::class)
        ->and(fn () => $this->inTenant($this->tenant, fn () => Queue::query()->findOrFail($foreign->id)))->toThrow(ModelNotFoundException::class)
        ->and($this->inTenant($this->tenant, fn () => Category::query()->count()))->toBe(0);
});

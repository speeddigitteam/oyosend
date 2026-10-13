<?php

declare(strict_types=1);

use App\Modules\Support\Actions\SupportReports;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Tests\Support\InteractsWithSupport;

uses(InteractsWithSupport::class);

/**
 * The reference is the straightforward per-ticket computation the report used before it moved into SQL.
 *
 * @return array<string, mixed>
 */
function referenceSupportOverview(string $from, string $to, string $zone, ?string $queueId): array
{
    $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $zone)->startOfDay()->utc();
    $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $zone)->endOfDay()->utc();
    $all = Ticket::query()->where('status', '!=', TicketStatus::Merged->value)
        ->when($queueId !== null, fn ($q) => $q->where('queue_id', $queueId))->orderBy('id')->get();
    $now = CarbonImmutable::now();

    $days = [];
    for ($day = $start->setTimezone($zone)->startOfDay(); $day->lessThanOrEqualTo($end); $day = $day->addDay()->startOfDay()) {
        $days[$day->toDateString()] = ['date' => $day->toDateString(), 'created' => 0, 'resolved' => 0];
    }
    $created = $resolved = 0;
    foreach ($all as $t) {
        $key = $t->created_at->toImmutable()->setTimezone($zone)->toDateString();
        if ($t->created_at->between($start, $end) && isset($days[$key])) {
            $days[$key]['created']++;
            $created++;
        }
        if ($t->resolved_at !== null && $t->resolved_at->between($start, $end)) {
            $key = $t->resolved_at->toImmutable()->setTimezone($zone)->toDateString();
            if (isset($days[$key])) {
                $days[$key]['resolved']++;
                $resolved++;
            }
        }
    }

    $byStatus = array_fill_keys(TicketStatus::activeValues(), 0);
    $byPriority = ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0];
    $aging = ['under_1_day' => 0, '1_to_3_days' => 0, '3_to_7_days' => 0, 'over_7_days' => 0];
    $byQueue = [];
    $unassigned = $total = 0;
    foreach ($all->filter(fn ($t) => $t->status->isActive()) as $t) {
        $total++;
        $byStatus[$t->status->value]++;
        $byPriority[$t->priority->value]++;
        $byQueue[$t->queue_id ?? ''] = ($byQueue[$t->queue_id ?? ''] ?? 0) + 1;
        $unassigned += $t->owner_id === null ? 1 : 0;
        $hours = (int) $t->created_at->diffInHours($now);
        $aging[match (true) {
            $hours < 24 => 'under_1_day', $hours < 72 => '1_to_3_days', $hours < 168 => '3_to_7_days', default => 'over_7_days',
        }]++;
    }

    $outcome = function ($done, $due, $breached, $status) {
        if ($done !== null) {
            return $due !== null && $done->greaterThan($due) ? 'missed' : 'met';
        }

        return $breached !== null || ($due !== null && $due->isPast() && $status->isActive()) ? 'missed' : 'pending';
    };
    $first = $res = ['met' => 0, 'missed' => 0, 'pending' => 0];
    $ft = $rt = [];
    foreach ($all->filter(fn ($t) => $t->created_at->between($start, $end)) as $t) {
        if ($t->first_responded_at !== null) {
            $ft[] = max(0, intdiv($t->first_responded_at->getTimestamp() - $t->created_at->getTimestamp(), 60));
        }
        if ($t->resolved_at !== null) {
            $rt[] = max(0, intdiv($t->resolved_at->getTimestamp() - $t->created_at->getTimestamp(), 60));
        }
        if ($t->sla_policy_id === null) {
            continue;
        }
        if ($t->first_response_due_at !== null || $t->first_responded_at !== null) {
            $first[$outcome($t->first_responded_at, $t->first_response_due_at, $t->first_response_breached_at, $t->status)]++;
        }
        if ($t->resolution_due_at !== null || $t->resolved_at !== null) {
            $res[$t->resolution_first_breached_at !== null ? 'missed' : $outcome($t->resolved_at, $t->resolution_due_at, $t->resolution_breached_at, $t->status)]++;
        }
    }
    $rate = fn (int $p, int $w) => $w === 0 ? null : intdiv($p * 20000 + $w, $w * 2);
    $avg = fn (array $v) => $v === [] ? null : intdiv(array_sum($v) * 2 + count($v), count($v) * 2);
    $median = function (array $v) {
        if ($v === []) {
            return null;
        }
        sort($v);
        $m = intdiv(count($v), 2);

        return count($v) % 2 === 1 ? $v[$m] : intdiv($v[$m - 1] + $v[$m], 2);
    };

    return [
        'volume' => ['created' => $created, 'resolved' => $resolved, 'byDay' => array_values($days)],
        'backlog' => ['total' => $total, 'unassigned' => $unassigned, 'byStatus' => $byStatus, 'byPriority' => $byPriority, 'aging' => $aging, 'queues' => $byQueue],
        'sla' => [
            'firstResponse' => $first + ['rateBps' => $rate($first['met'], $first['met'] + $first['missed'])],
            'resolution' => $res + ['rateBps' => $rate($res['met'], $res['met'] + $res['missed'])],
        ],
        'times' => ['firstResponseAverage' => $avg($ft), 'firstResponseMedian' => $median($ft), 'resolutionAverage' => $avg($rt), 'resolutionMedian' => $median($rt)],
    ];
}

it('matches a per-ticket reference computation over a few hundred generated tickets', function (string $zone) {
    $tenant = $this->createTenant('sup-agg-'.strtolower(str_replace(['/', '_'], '-', $zone)), ['timezone' => $zone, 'currency' => 'BDT']);
    $this->entitle($tenant, 'support');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 09:17:41', 'UTC'));
    mt_srand(20260320);

    $queues = [$this->supportQueue($tenant)->id, $this->supportQueue($tenant)->id];
    $policy = $this->supportPolicy($tenant)->id;
    $statuses = ['new', 'open', 'waiting_customer', 'on_hold', 'resolved', 'closed', 'merged'];
    $owner = $this->membershipOf($tenant, $this->ownerOf($tenant))->id;

    $this->inTenant($tenant, function () use ($queues, $policy, $statuses, $owner): void {
        $now = CarbonImmutable::now('UTC');
        Ticket::unguarded(function () use ($queues, $policy, $statuses, $owner, $now): void {
            for ($i = 0; $i < 320; $i++) {
                $created = $now->subMinutes(mt_rand(0, 60 * 24 * 40))->setTime(mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59));
                $status = $statuses[mt_rand(0, 6)];
                $responded = mt_rand(0, 2) > 0 ? $created->addMinutes(mt_rand(-5, 600)) : null;
                $resolved = in_array($status, ['resolved', 'closed'], true) || mt_rand(0, 6) === 0 ? $created->addMinutes(mt_rand(-5, 5000)) : null;
                Ticket::query()->create([
                    'number' => 'AGG-'.$i, 'subject' => 'Ticket '.$i, 'status' => $status,
                    'priority' => ['low', 'normal', 'high', 'urgent'][mt_rand(0, 3)], 'channel' => 'web',
                    'queue_id' => mt_rand(0, 3) === 0 ? null : $queues[mt_rand(0, 1)],
                    'owner_id' => mt_rand(0, 2) === 0 ? $owner : null,
                    'sla_policy_id' => mt_rand(0, 3) > 0 ? $policy : null,
                    'first_responded_at' => $responded, 'resolved_at' => $resolved,
                    'first_response_due_at' => mt_rand(0, 4) > 0 ? $created->addMinutes(mt_rand(10, 900)) : null,
                    'resolution_due_at' => mt_rand(0, 4) > 0 ? $created->addMinutes(mt_rand(60, 9000)) : null,
                    'first_response_breached_at' => mt_rand(0, 9) === 0 ? $created->addMinutes(30) : null,
                    'resolution_breached_at' => mt_rand(0, 9) === 0 ? $created->addMinutes(300) : null,
                    'resolution_first_breached_at' => mt_rand(0, 12) === 0 ? $created->addMinutes(300) : null,
                    'created_at' => $created, 'updated_at' => $created,
                ]);
            }
        });
    });

    $this->inTenant($tenant, function () use ($zone, $queues): void {
        foreach ([[null, '2026-02-10', '2026-03-20'], [null, '2026-03-01', '2026-03-12'], [$queues[0], '2026-02-25', '2026-03-20']] as [$queueId, $from, $to]) {
            $report = app(SupportReports::class)->overview($from, $to, $queueId);
            $ref = referenceSupportOverview($from, $to, $zone, $queueId);

            expect($report['volume'])->toBe($ref['volume'])
                ->and($report['sla'])->toBe($ref['sla'])
                ->and($report['times'])->toBe($ref['times'])
                ->and($report['backlog']['total'])->toBe($ref['backlog']['total'])
                ->and($report['backlog']['unassigned'])->toBe($ref['backlog']['unassigned'])
                ->and($report['backlog']['byStatus'])->toBe($ref['backlog']['byStatus'])
                ->and($report['backlog']['byPriority'])->toBe($ref['backlog']['byPriority'])
                ->and($report['backlog']['aging'])->toBe($ref['backlog']['aging'])
                ->and(collect($report['backlog']['byQueue'])->mapWithKeys(fn ($r) => [$r['queueId'] ?? '' => $r['count']])->sortKeys()->all())
                ->toBe(collect($ref['backlog']['queues'])->sortKeys()->all())
                ->and($report['volume']['created'])->toBeGreaterThan(0);
        }
    });
})->with(['Asia/Dhaka', 'America/New_York', 'Asia/Kolkata']);

it('lets a narrower manager edit queue fields but needs assign-all to change the queue agents', function () {
    $tenant = $this->supportTenant('sup-qgate');
    $agent = $this->supportAgent($tenant, $this->membershipOf($tenant, $this->ownerOf($tenant)));
    $queue = $this->supportQueue($tenant, ['agent_ids' => [$agent->id]]);
    [$narrow] = $this->supportMember($tenant, ['support.queues.manage', 'support.tickets.assign'], ['support.tickets.assign' => 'own']);
    [$wide] = $this->supportMember($tenant, ['support.queues.manage', 'support.tickets.assign'], ['support.tickets.assign' => 'all']);
    $url = fn (string $path = '') => $this->supportUrl($tenant, $path);
    $agents = fn (): array => $this->inTenant($tenant, fn () => QueueAgent::query()->where('queue_id', $queue->id)->pluck('agent_id')->all());
    $body = ['name' => 'Renamed', 'assignment_mode' => 'manual'];

    // An unchanged set (or none) passes.
    $this->actingAsMember($tenant, $narrow)->put($url('/queues/'.$queue->id), $body + ['agent_ids' => [$agent->id]])->assertRedirect();
    $this->actingAsMember($tenant, $narrow)->put($url('/queues/'.$queue->id), $body)->assertRedirect();
    expect($agents())->toBe([$agent->id])
        ->and($this->inTenant($tenant, fn () => Queue::query()->findOrFail($queue->id)->name))->toBe('Renamed');

    // A changed set is refused, on update and on create.
    $this->actingAsMember($tenant, $narrow)->put($url('/queues/'.$queue->id), $body + ['agent_ids' => []])->assertForbidden();
    $this->actingAsMember($tenant, $narrow)->post($url('/queues'), ['name' => 'Fresh', 'assignment_mode' => 'manual', 'agent_ids' => [$agent->id]])->assertForbidden();
    expect($agents())->toBe([$agent->id]);
    $this->actingAsMember($tenant, $narrow)->post($url('/queues'), ['name' => 'Fresh', 'assignment_mode' => 'manual', 'agent_ids' => []])->assertRedirect();

    // Someone holding assign at scope all may change it.
    $this->actingAsMember($tenant, $wide)->put($url('/queues/'.$queue->id), $body + ['agent_ids' => []])->assertRedirect();
    expect($agents())->toBe([]);
});

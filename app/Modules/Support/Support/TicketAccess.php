<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\QueueAgent;
use App\Modules\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record access for tickets: {@see RecordAccess} (own = assigned to me, department, branch, all) plus one
 * team rule. An agent enrolled in a queue also reaches the *unassigned* tickets of that queue with any scope
 * they hold, so a ticket nobody owns yet can be picked up. Nothing else is widened.
 */
final class TicketAccess
{
    /** @var array<string, list<string>> */
    private array $queues = [];

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function constrain(Builder $query, User $actor, string $permission): Builder
    {
        $scope = $this->access->scopeFor($actor, $permission);

        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($scope === AccessScope::All) {
            return $query;
        }

        $queueIds = $this->queueIds($actor);
        $model = $query->getModel();

        return $query->where(function (Builder $match) use ($actor, $permission, $queueIds, $model): void {
            $match->where(function (Builder $own) use ($actor, $permission): void {
                $this->records->constrain($own, $actor, $permission);
            });

            if ($queueIds !== []) {
                $match->orWhere(function (Builder $queue) use ($queueIds, $model): void {
                    $queue->whereNull($model->qualifyColumn('owner_id'))
                        ->whereIn($model->qualifyColumn('queue_id'), $queueIds);
                });
            }
        });
    }

    public function allows(User $actor, string $permission, Ticket $ticket): bool
    {
        if ($this->records->allows($actor, $permission, $ticket)) {
            return true;
        }

        return $ticket->owner_id === null
            && $ticket->queue_id !== null
            && $this->access->scopeFor($actor, $permission) !== null
            && in_array($ticket->queue_id, $this->queueIds($actor), true);
    }

    public function authorize(User $actor, string $permission, Ticket $ticket): void
    {
        abort_unless($this->allows($actor, $permission, $ticket), 404);
    }

    /** Whether the actor holds the permission for every record (needed to hand a ticket to someone else). */
    public function hasAll(User $actor, string $permission): bool
    {
        return $this->access->scopeFor($actor, $permission) === AccessScope::All;
    }

    /** @return list<string> */
    private function queueIds(User $actor): array
    {
        $membership = $this->access->membership($actor);

        if ($membership === null) {
            return [];
        }

        $key = $membership->tenant_id.':'.$membership->id;

        return $this->queues[$key] ??= QueueAgent::query()
            ->whereIn('agent_id', Agent::query()->where('membership_id', $membership->id)->where('is_active', true)->select('id'))
            ->pluck('queue_id')
            ->all();
    }
}

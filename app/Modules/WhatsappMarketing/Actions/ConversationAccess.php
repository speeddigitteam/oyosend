<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record access for conversations: {@see RecordAccess} (own = assigned to me, department, branch, all) plus one
 * team rule. An enrolled, active agent also reaches the *unassigned* conversations with any scope they hold, so
 * a conversation nobody owns yet can be picked up. Nothing else is widened.
 */
final class ConversationAccess
{
    /** @var array<string, bool> */
    private array $agents = [];

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
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

        $model = $query->getModel();
        $agent = $this->isAgent($actor);

        return $query->where(function (Builder $match) use ($actor, $permission, $agent, $model): void {
            $match->where(function (Builder $own) use ($actor, $permission): void {
                $this->records->constrain($own, $actor, $permission);
            });

            if ($agent) {
                $match->orWhereNull($model->qualifyColumn('owner_id'));
            }
        });
    }

    public function allows(User $actor, string $permission, Conversation $conversation): bool
    {
        if ($this->records->allows($actor, $permission, $conversation)) {
            return true;
        }

        return $conversation->owner_id === null
            && $this->access->scopeFor($actor, $permission) !== null
            && $this->isAgent($actor);
    }

    public function authorize(User $actor, string $permission, Conversation $conversation): void
    {
        abort_unless($this->allows($actor, $permission, $conversation), 404);
    }

    /** Whether the actor holds the permission for every record (needed to hand a conversation to someone else). */
    public function hasAll(User $actor, string $permission): bool
    {
        return $this->access->scopeFor($actor, $permission) === AccessScope::All;
    }

    public function isAgent(User $actor): bool
    {
        $membership = $this->access->membership($actor);

        if ($membership === null) {
            return false;
        }

        $key = $membership->tenant_id.':'.$membership->id;

        return $this->agents[$key] ??= Agent::query()->where('membership_id', $membership->id)->where('is_active', true)->exists();
    }
}

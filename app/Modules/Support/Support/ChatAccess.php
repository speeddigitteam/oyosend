<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\ChatConversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which website chats a member reaches with a (scopable) ticket permission. Website chats reuse
 * `support.tickets.view` and `support.tickets.reply`:
 *
 *  - held for `all` records: every chat of the workspace;
 *  - held for a narrower scope (own, department or branch): the chats assigned to the member, plus the unassigned
 *    ones when the member is an active enrolled support agent, so a waiting chat can be picked up (the same pool
 *    rule as {@see TicketAccess} for queue tickets). A chat carries no department or branch, so those scopes reach
 *    no further than `own`.
 */
final class ChatAccess
{
    /** @var array<string, bool> */
    private array $agents = [];

    public function __construct(private readonly AccessChecker $access) {}

    /**
     * @param  Builder<ChatConversation>  $query
     * @return Builder<ChatConversation>
     */
    public function constrain(Builder $query, User $actor, string $permission): Builder
    {
        $scope = $this->access->scopeFor($actor, $permission);
        $membership = $this->access->membership($actor);

        if ($scope === null || $membership === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($scope === AccessScope::All) {
            return $query;
        }

        $column = $query->getModel()->qualifyColumn('assignee_membership_id');
        $pool = $this->isAgent($membership);

        return $query->where(function (Builder $match) use ($column, $membership, $pool): void {
            $match->where($column, $membership->id);

            if ($pool) {
                $match->orWhereNull($column);
            }
        });
    }

    public function allows(User $actor, string $permission, ChatConversation $conversation): bool
    {
        $scope = $this->access->scopeFor($actor, $permission);
        $membership = $this->access->membership($actor);

        if ($scope === null || $membership === null) {
            return false;
        }

        if ($scope === AccessScope::All || $conversation->assignee_membership_id === $membership->id) {
            return true;
        }

        return $conversation->assignee_membership_id === null && $this->isAgent($membership);
    }

    /** A chat outside the member's reach is not disclosed: 404. */
    public function authorize(User $actor, string $permission, ChatConversation $conversation): void
    {
        abort_unless($this->allows($actor, $permission, $conversation), 404);
    }

    private function isAgent(TenantMembership $membership): bool
    {
        return $this->agents[$membership->tenant_id.':'.$membership->id] ??= Agent::query()
            ->where('membership_id', $membership->id)
            ->where('is_active', true)
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Ai\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversations are private to one member: every query here is narrowed to the member, so even the
 * workspace owner cannot read or delete someone else conversation.
 */
final class ConversationManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Builder<Conversation>
     */
    public function mine(TenantMembership $membership): Builder
    {
        return Conversation::query()->where('membership_id', $membership->id);
    }

    public function start(TenantMembership $membership, string $firstMessage = ''): Conversation
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $firstMessage));

        $conversation = new Conversation([
            'title' => $title === '' ? __('New conversation') : Str::limit($title, 80, '...'),
            'last_message_at' => Carbon::now(),
        ]);
        $conversation->membership_id = $membership->id;
        $conversation->save();

        return $conversation;
    }

    public function delete(Conversation $conversation, User $actor): void
    {
        DB::transaction(function () use ($conversation, $actor): void {
            $id = $conversation->id;
            $conversation->delete();

            $this->audit->record('ai.conversation.deleted', null, ['conversation_id' => $id], $actor);
        });
    }

    /**
     * Delete every conversation of the member.
     */
    public function clear(TenantMembership $membership, User $actor): int
    {
        return DB::transaction(function () use ($membership, $actor): int {
            $count = $this->mine($membership)->count();
            $this->mine($membership)->delete();

            $this->audit->record('ai.conversations.cleared', null, ['count' => $count], $actor);

            return $count;
        });
    }
}

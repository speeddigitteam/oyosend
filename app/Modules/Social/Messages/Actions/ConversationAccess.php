<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\MessagingSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which direct-message conversations a member may see or answer. One place for the Social Messages page and the
 * unified inbox sources, so both narrow exactly alike:
 *
 *  - the record scope of the permission (`own` / `department` / `branch` follow the assigned member, `all`);
 *  - the account allow-list: a Page with allow-list entries is reached only by its owner and the members or roles
 *    allowed to publish for it (answering a message speaks as the Page);
 *  - only platforms the operator switched on, and only Pages whose message capture is on (Instagram only while the
 *    Page captures it). Stored conversations of a Page switched off are kept but not shown.
 */
final class ConversationAccess
{
    public const string VIEW = 'social.messages.view';

    public const string REPLY = 'social.messages.reply';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccountAllowList $allowList,
    ) {}

    /** @return Builder<DirectConversation> */
    public function visible(User $viewer, string $permission = self::VIEW, ?string $platform = null): Builder
    {
        $platforms = MessagingAvailability::platforms();

        if ($platform !== null) {
            $platforms = array_values(array_intersect($platforms, [$platform]));
        }

        $query = $this->records->constrain(DirectConversation::query(), $viewer, $permission);

        if ($platforms === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('social_dm_conversations.platform', $platforms)
            ->whereIn('social_dm_conversations.social_account_id', $this->allowList->allowedIds($viewer, AccountAllowList::PUBLISH))
            ->whereIn('social_dm_conversations.social_account_id', MessagingSetting::query()->where('is_enabled', true)->select('social_account_id'))
            ->where(fn (Builder $captured) => $captured
                ->where('social_dm_conversations.platform', DirectConversation::MESSENGER)
                ->orWhereIn('social_dm_conversations.social_account_id', MessagingSetting::query()
                    ->where('is_enabled', true)->where('instagram_state', MessagingSetting::ON)->select('social_account_id')));
    }

    /** Whether the member may act on this one conversation with the permission (view or reply). */
    public function allows(User $viewer, string $permission, DirectConversation $conversation): bool
    {
        return $this->visible($viewer, $permission)->whereKey($conversation->getKey())->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\WhatsappMarketing\Actions\Analytics;
use App\Modules\WhatsappMarketing\Actions\ChannelStatus;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/** Dashboard counts of the conversations the person may see (`conversations.view`, with their scope). */
final class WhatsappOverviewWidget
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly AccessChecker $checker,
        private readonly ChannelStatus $channel,
        private readonly Analytics $analytics,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(User $user): array
    {
        $membershipId = $this->checker->membership($user)?->id;
        $open = fn (): Builder => $this->access
            ->constrain(Conversation::query(), $user, Permissions::CONVERSATIONS_VIEW)
            ->where('status', ConversationStatus::Open->value);

        return [
            'open' => $open()->count(),
            'unassigned' => $open()->whereNull('owner_id')->count(),
            'mine' => $membershipId === null ? 0 : $open()->where('owner_id', $membershipId)->count(),
            'unread' => $open()->where('unread_count', '>', 0)->count(),
            'campaigns' => $this->analytics->activeCampaigns(),
            'connected' => $this->channel->isAvailable(),
        ];
    }
}

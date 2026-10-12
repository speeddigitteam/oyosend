<?php

declare(strict_types=1);

namespace App\Modules\Social\Events;

use App\Modules\Social\Models\SocialAccount;

/** A connected account was rejected by its platform and must be authorised again. */
final class AccountNeedsReauthorization extends SocialEvent
{
    public static function from(SocialAccount $account): self
    {
        return new self(['account_id' => $account->id, 'platform' => $account->platform]);
    }

    public static function name(): string
    {
        return 'social.account.needs_reauthorization';
    }
}

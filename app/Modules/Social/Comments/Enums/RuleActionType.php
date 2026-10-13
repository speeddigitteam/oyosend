<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Enums;

/** What a comment rule may do. A closed list: a rule can never name anything else. */
enum RuleActionType: string
{
    case Reply = 'reply';
    case Hide = 'hide';
    case Assign = 'assign';
    case Tag = 'tag';
    case Review = 'review';
    case PrivateReply = 'private_reply';

    /** The permission the rule's member must hold, for every record, to use this action. */
    public function permission(): string
    {
        return match ($this) {
            self::Reply, self::PrivateReply => 'social.comments.reply',
            self::Hide, self::Assign, self::Tag, self::Review => 'social.comments.moderate',
        };
    }

    /** Whether it changes what the audience sees on the platform. */
    public function isOutward(): bool
    {
        return in_array($this, [self::Reply, self::Hide, self::PrivateReply], true);
    }
}

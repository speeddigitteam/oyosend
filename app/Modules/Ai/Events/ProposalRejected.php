<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** A member rejected an AI-suggested change. */
final class ProposalRejected extends AiEvent
{
    public static function name(): string
    {
        return 'ai.proposal.rejected';
    }
}

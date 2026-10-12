<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

/** A member confirmed an AI-suggested change and it was applied through the module action. */
final class ProposalConfirmed extends AiEvent
{
    public static function name(): string
    {
        return 'ai.proposal.confirmed';
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\InteractionRecorder;
use App\Core\Communication\Data\InteractionEntry;

/**
 * Bound when no communication module is installed in the build. It records nothing and says so.
 */
final class NullInteractionRecorder implements InteractionRecorder
{
    public function record(InteractionEntry $entry): bool
    {
        return false;
    }
}

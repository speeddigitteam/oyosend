<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\InteractionEntry;

/**
 * Appends an entry to the shared interaction timeline of a contact or company.
 *
 * Like {@see Notifier} it is the whole surface another module sees; the timeline's tables belong to the
 * `communication` module. With no communication app in the workspace it is a no-op returning false.
 */
interface InteractionRecorder
{
    /**
     * @return bool True when an entry was written; false when the app is not available, the subject is
     *              unknown, or the dedupe key had been recorded before.
     */
    public function record(InteractionEntry $entry): bool;
}

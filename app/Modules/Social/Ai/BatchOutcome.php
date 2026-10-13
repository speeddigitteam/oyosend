<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Core\Ai\Text\TextState;

/**
 * What one sentiment batch did. `stopped` is set when the assistant answered anything but `ok`: the rest of
 * the batch is left untouched (`pending`) because asking again right now would fail the same way.
 */
final readonly class BatchOutcome
{
    /**
     * @param  int  $analysed  Comments scored by a call in this run.
     * @param  int  $unchanged  Comments that already had a result for the same text (no call made).
     * @param  int  $skipped  Empty text or an invalid id.
     * @param  int  $pending  Comments not reached: the daily cap, or the assistant stopped the batch.
     */
    public function __construct(
        public int $analysed = 0,
        public int $unchanged = 0,
        public int $skipped = 0,
        public int $pending = 0,
        public bool $capReached = false,
        public ?TextState $stopped = null,
        public bool $retryable = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'analysed' => $this->analysed,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'pending' => $this->pending,
            'cap_reached' => $this->capReached,
            'stopped' => $this->stopped?->value,
            'retryable' => $this->retryable,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

/**
 * Which run is executing an action right now.
 *
 * A domain event raised by that action may start another workflow. The trigger bridge asks the scope so the
 * new run records its parent and depth, and chains of workflows triggering workflows end at the depth limit.
 * Bound as a scoped service: a worker gets a fresh, empty scope for every job.
 */
final class RunScope
{
    private ?string $runId = null;

    private int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(string $runId, int $depth, callable $callback): mixed
    {
        $previous = [$this->runId, $this->depth];
        [$this->runId, $this->depth] = [$runId, $depth];

        try {
            return $callback();
        } finally {
            [$this->runId, $this->depth] = $previous;
        }
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function depth(): int
    {
        return $this->depth;
    }
}

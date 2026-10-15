<?php

declare(strict_types=1);

namespace App\Modules\Communication\Realtime;

use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Realtime\Realtime;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts {@see InboxChanged} for the active workspace once the write commits (ADR 0035).
 *
 *  - Realtime off (no Reverb configured, the test suite): nothing at all, not even a queued job.
 *  - No active workspace: nothing (an inbox belongs to one).
 *  - Inside a transaction: queued when the outermost one commits, inside its workspace; dropped when it rolls back.
 *  - A failure to queue or deliver is logged by class only (an exception message could carry the server address
 *    or a signed query) and never reaches the write: the data is committed, and the page's polling still sees it.
 *
 * Signals are not merged here: two changes in one request send two, and the page collapses them (it waits a moment
 * and asks once). Merging on the server would risk dropping the later of two changes.
 */
final class BroadcastInboxSignals implements InboxSignals
{
    public function __construct(
        private readonly Realtime $realtime,
        private readonly TenantContext $context,
        private readonly Dispatcher $events,
    ) {}

    public function changed(string $source, string $id): void
    {
        if ($source === '' || $id === '' || ! $this->context->check() || ! $this->realtime->enabled()) {
            return;
        }

        $this->context->afterCommit(function (Tenant $tenant) use ($source, $id): void {
            try {
                $this->events->dispatch(new InboxChanged((string) $tenant->getKey(), $source, $id));
            } catch (Throwable $exception) {
                Log::warning('An inbox realtime signal could not be sent.', ['exception' => $exception::class, 'source' => $source]);
            }
        });
    }
}

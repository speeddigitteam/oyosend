<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Ai\Enums\ProposalStatus;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Proposal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies the workspace retention setting: conversations (with their messages and proposals) not used for
 * `retention_days` are deleted, and pending proposals past their expiry are marked expired. Runs for the
 * active workspace, from the entitlement-gated scheduler.
 */
final class RetentionEnforcer
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{conversations: int, proposals: int}
     */
    public function run(): array
    {
        $days = max(1, $this->settings->current()->retention_days);
        $cutoff = Carbon::now()->subDays($days);

        return DB::transaction(function () use ($cutoff, $days): array {
            $expired = Proposal::query()
                ->where('status', ProposalStatus::Pending)
                ->where('expires_at', '<', Carbon::now())
                ->update(['status' => ProposalStatus::Expired->value, 'decided_at' => Carbon::now()]);

            $deleted = Conversation::query()
                ->where(fn ($query) => $query->where('last_message_at', '<', $cutoff)
                    ->orWhere(fn ($old) => $old->whereNull('last_message_at')->where('created_at', '<', $cutoff)))
                ->delete();

            if ($deleted > 0) {
                $this->audit->record('ai.retention.applied', null, ['conversations' => $deleted, 'retention_days' => $days]);
            }

            return ['conversations' => (int) $deleted, 'proposals' => (int) $expired];
        });
    }
}

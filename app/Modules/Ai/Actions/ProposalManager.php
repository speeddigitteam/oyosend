<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Ai\ProposalDraft;
use App\Core\Ai\ToolContext;
use App\Core\Audit\AuditLogger;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Enums\ProposalStatus;
use App\Modules\Ai\Events\ProposalConfirmed;
use App\Modules\Ai\Events\ProposalRejected;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Proposal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only way an AI suggestion changes anything: a person confirms a stored proposal, and the change then
 * runs as that person through the module action (its validation, authorization and audit).
 */
final class ProposalManager
{
    /** Hours a proposal can wait for a decision. */
    public const int TTL_HOURS = 24;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Conversation $conversation, ToolContext $context, string $tool, ProposalDraft $draft): Proposal
    {
        return DB::transaction(function () use ($conversation, $context, $tool, $draft): Proposal {
            $proposal = new Proposal([
                'conversation_id' => $conversation->id,
                'membership_id' => $context->membership->id,
                'tool' => $tool,
                'summary' => mb_substr($draft->summary, 0, 500),
                'payload' => $draft->payload,
                'status' => ProposalStatus::Pending,
                'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            ]);
            $proposal->save();

            $this->audit->record('ai.proposal.created', null, [
                'proposal_id' => $proposal->id,
                'tool' => $tool,
                'record_ids' => $draft->recordIds,
                'conversation_id' => $conversation->id,
            ], $context->user);

            return $proposal;
        });
    }

    /**
     * @throws ValidationException
     */
    public function confirm(Proposal $proposal, ToolContext $context): Proposal
    {
        $this->assertDecidable($proposal, $context);

        $tool = $this->tools->findProposalTool($proposal->tool, $context->user);

        if ($tool === null || ! $this->settings->allows(Feature::Chat)) {
            throw ValidationException::withMessages(['proposal' => __('You can no longer apply this change.')]);
        }

        $stale = false;

        try {
            DB::transaction(function () use ($proposal, $tool, $context, &$stale): void {
                // Serialize concurrent confirms (double click, two tabs): the row is locked and re-read, so
                // only one of them can still see it pending and apply it.
                $proposal = $this->lockPending($proposal, $context);

                if ($proposal === null) {
                    $stale = true;

                    return;
                }

                $result = $tool->apply($context, $proposal->payload);

                $proposal->forceFill([
                    'status' => ProposalStatus::Confirmed,
                    'result' => $result,
                    'decided_at' => Carbon::now(),
                ])->save();

                $this->audit->record('ai.proposal.confirmed', null, [
                    'proposal_id' => $proposal->id,
                    'tool' => $proposal->tool,
                    'result' => $result,
                ], $context->user);

                ProposalConfirmed::dispatch([
                    'proposal_id' => $proposal->id,
                    'tool' => $proposal->tool,
                    'conversation_id' => $proposal->conversation_id,
                    'membership_id' => $proposal->membership_id,
                ]);
            });
        } catch (ValidationException $exception) {
            $this->fail($proposal, $context);

            throw $exception;
        } catch (HttpException) {
            $this->fail($proposal, $context);

            throw ValidationException::withMessages(['proposal' => __('That record is no longer available to you.')]);
        }

        if ($stale) {
            throw ValidationException::withMessages(['proposal' => __('This proposal was already decided.')]);
        }

        return $proposal->refresh();
    }

    public function reject(Proposal $proposal, ToolContext $context): Proposal
    {
        $this->assertDecidable($proposal, $context);

        $stale = false;

        DB::transaction(function () use ($proposal, $context, &$stale): void {
            $proposal = $this->lockPending($proposal, $context);

            if ($proposal === null) {
                $stale = true;

                return;
            }

            $proposal->forceFill(['status' => ProposalStatus::Rejected, 'decided_at' => Carbon::now()])->save();

            $this->audit->record('ai.proposal.rejected', null, [
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
            ], $context->user);

            ProposalRejected::dispatch([
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
                'conversation_id' => $proposal->conversation_id,
                'membership_id' => $proposal->membership_id,
            ]);
        });

        if ($stale) {
            throw ValidationException::withMessages(['proposal' => __('This proposal was already decided.')]);
        }

        return $proposal->refresh();
    }

    /**
     * Re-read the proposal under a row lock inside the deciding transaction. Null when another request
     * decided it first (or it expired meanwhile), so the caller must not act.
     */
    private function lockPending(Proposal $proposal, ToolContext $context): ?Proposal
    {
        $locked = Proposal::query()->lockForUpdate()->find($proposal->id);

        if ($locked === null
            || $locked->membership_id !== $context->membership->id
            || $locked->status !== ProposalStatus::Pending
            || $locked->expires_at->isPast()) {
            return null;
        }

        return $locked;
    }

    private function assertDecidable(Proposal $proposal, ToolContext $context): void
    {
        // Proposals are personal: only the member the assistant worked for may decide.
        abort_unless($proposal->membership_id === $context->membership->id, 404);

        if ($proposal->status !== ProposalStatus::Pending) {
            throw ValidationException::withMessages(['proposal' => __('This proposal was already decided.')]);
        }

        if ($proposal->expires_at->isPast()) {
            $proposal->forceFill(['status' => ProposalStatus::Expired, 'decided_at' => Carbon::now()])->save();

            throw ValidationException::withMessages(['proposal' => __('This proposal has expired. Ask the assistant again.')]);
        }
    }

    private function fail(Proposal $proposal, ToolContext $context): void
    {
        // Only a still-pending proposal can fail; a decided one keeps its outcome.
        $updated = Proposal::query()->whereKey($proposal->id)->where('status', ProposalStatus::Pending->value)
            ->update(['status' => ProposalStatus::Failed->value, 'decided_at' => Carbon::now()]);

        if ($updated !== 1) {
            return;
        }

        $this->audit->record('ai.proposal.failed', null, [
            'proposal_id' => $proposal->id,
            'tool' => $proposal->tool,
        ], $context->user);
    }
}

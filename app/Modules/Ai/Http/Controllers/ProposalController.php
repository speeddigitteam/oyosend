<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Ai\Actions\ProposalManager;
use App\Modules\Ai\Models\Proposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirm or reject an AI proposal. Only the member the proposal was made for can decide; confirming
 * re-checks that member permission and record scope when the module action runs.
 */
final class ProposalController
{
    public function __construct(
        private readonly AiControllerSupport $support,
        private readonly ProposalManager $proposals,
    ) {}

    public function confirm(Request $request, string $proposal): RedirectResponse
    {
        $context = $this->support->context($request);
        $record = $this->find($proposal, $context->membership->id);

        $this->proposals->confirm($record, $context);
        Toast::success(__('Change applied.'));

        return back();
    }

    public function reject(Request $request, string $proposal): RedirectResponse
    {
        $context = $this->support->context($request);
        $record = $this->find($proposal, $context->membership->id);

        $this->proposals->reject($record, $context);
        Toast::info(__('Proposal rejected. Nothing was changed.'));

        return back();
    }

    private function find(string $id, string $membershipId): Proposal
    {
        return Proposal::query()->where('membership_id', $membershipId)->findOrFail($id);
    }
}
